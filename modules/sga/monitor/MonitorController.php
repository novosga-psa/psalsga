<?php

namespace modules\sga\monitor;

use Exception;
use Novosga\Context;
use Novosga\Util\Arrays;
use Novosga\Model\Unidade;
use Novosga\Http\JsonResponse;
use Novosga\Controller\ModuleController;
use Novosga\Service\AtendimentoService;
use Novosga\Service\FilaService;
use Novosga\Service\ServicoService;
use Doctrine\ORM\Query\ResultSetMapping;

/**
 * MonitorController.
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
class MonitorController extends ModuleController
{
    public function index(Context $context)
    {
        $unidade = $context->getUser()->getUnidade();
        $this->app()->view()->set('unidade', $unidade);
        if ($unidade) {
            // servicos
            $this->app()->view()->set('servicos', $this->servicos($unidade, ' e.status = 1 '));
        }
        // lista de prioridades para ser utilizada ao redirecionar senha
        $query = $this->em()->createQuery("SELECT e FROM Novosga\Model\Prioridade e WHERE e.status = 1 ORDER BY e.peso, e.nome");
        $this->app()->view()->set('prioridades', $query->getResult());
        $this->app()->view()->set('milis', time() * 1000);

        $this->listarUsuarios( $unidade->getId() );
    }
    
    private function servicos(Unidade $unidade, $where = '')
    {
        $service = new ServicoService($this->em());

        return $service->servicosUnidade($unidade, $where);
    }

    public function ajax_update(Context $context)
    {
        $response = new JsonResponse();
        $unidade = $context->getUnidade();
        $filaService = new FilaService($this->em());
        if ($unidade) {
            $ids = $context->request()->get('ids');
            $ids = Arrays::valuesToInt(explode(',', $ids));
            if (sizeof($ids)) {
                $response->data['total'] = 0;
                $servicos = $this->servicos($unidade, ' e.servico IN ('.implode(',', $ids).') ');
                $em = $context->database()->createEntityManager();
                if ($servicos) {
                    foreach ($servicos as $su) {
                        $rs = $filaService->filaServico($unidade, $su->getServico());
                        $total = count($rs);
                        // prevent overhead
                        if ($total) {
                            $fila = array();
                            foreach ($rs as $atendimento) {
                                $arr = $atendimento->jsonSerialize(true);
                                $fila[] = $arr;
                            }
                            $response->data['servicos'][$su->getServico()->getId()] = $fila;
                            ++$response->data['total'];
                        }
                    }
                }
                $response->success = true;
            }
        }

        return $response;
    }

    public function info_senha(Context $context)
    {
        $response = new JsonResponse();
        $unidade = $context->getUser()->getUnidade();
        if ($unidade) {
            $id = (int) $context->request()->get('id');
            $service = new AtendimentoService($this->em());
            $atendimento = $service->buscaAtendimento($unidade, $id);
            if ($atendimento) {
                $response->data = $atendimento->jsonSerialize();
                $response->success = true;
            } else {
                $response->message = _('Atendimento inválido');
            }
        }

        return $response;
    }

    /**
     * Busca os atendimentos a partir do número da senha.
     *
     * @param Novosga\Context $context
     */
    public function buscar(Context $context)
    {
        $response = new JsonResponse();
        $unidade = $context->getUser()->getUnidade();
        if ($unidade) {
            $numero = $context->request()->get('numero');
            $service = new AtendimentoService($this->em());
            $atendimentos = $service->buscaAtendimentos($unidade, $numero);
            $response->data['total'] = sizeof($atendimentos);
            foreach ($atendimentos as $atendimento) {
                $response->data['atendimentos'][] = $atendimento->jsonSerialize();
            }
            $response->success = true;
        } else {
            $response->message = _('Nenhuma unidade selecionada');
        }

        return $response;
    }

    /**
     * Transfere o atendimento para outro serviço e prioridade.
     *
     * @param Novosga\Context $context
     */
    public function transferir(Context $context)
    {
        $response = new JsonResponse();
        try {
            $unidade = $context->getUser()->getUnidade();
            if (!$unidade) {
                throw new Exception(_('Nenhuma unidade selecionada'));
            }
            $id = (int) $context->request()->post('id');
            $atendimento = $this->getAtendimento($unidade, $id);
            /*
             * TODO: verificar se o servico informado esta disponivel para a unidade.
             */
            $servico = (int) $context->request()->post('servico');
            $prioridade = (int) $context->request()->post('prioridade');

            $service = new AtendimentoService($this->em());
            $response->success = $service->transferir($atendimento, $unidade, $servico, $prioridade);
        } catch (Exception $e) {
            $response->message = $e->getMessage();
        }

        return $response;
    }

    /**
     * Reativa o atendimento para o mesmo serviço e mesma prioridade.
     * Só pode reativar atendimentos que foram: Cancelados ou Não Compareceu.
     *
     * @param Novosga\Context $context
     */
    public function reativar(Context $context)
    {
        $response = new JsonResponse();
        try {
            $unidade = $context->getUser()->getUnidade();
            if (!$unidade) {
                throw new Exception(_('Nenhuma unidade selecionada'));
            }
            $id = (int) $context->request()->post('id');
            $conn = $this->em()->getConnection();
            $status = implode(',', array(AtendimentoService::SENHA_CANCELADA, AtendimentoService::NAO_COMPARECEU));
            // reativa apenas se estiver finalizada (data fim diferente de nulo)
            $stmt = $conn->prepare("
                UPDATE
                    atendimentos
                SET
                    status = :status,
                    dt_fim = NULL
                WHERE
                    id = :id AND
                    unidade_id = :unidade AND
                    status IN ({$status})
            ");
            $stmt->bindValue('id', $id);
            $stmt->bindValue('status', AtendimentoService::SENHA_EMITIDA);
            $stmt->bindValue('unidade', $unidade->getId());
            $response->success = $stmt->execute() > 0;
        } catch (Exception $e) {
            $response->message = $e->getMessage();
        }

        return $response;
    }

    /**
     * Atualiza o status da senha para cancelado.
     *
     * @param Novosga\Context $context
     */
    public function cancelar(Context $context)
    {
        $response = new JsonResponse();
        try {
            $unidade = $context->getUser()->getUnidade();
            if (!$unidade) {
                throw new Exception(_('Nenhuma unidade selecionada'));
            }
            $id = (int) $context->request()->post('id');
            $atendimento = $this->getAtendimento($unidade, $id);
            $service = new AtendimentoService($this->em());
            $response->success = $service->cancelar($atendimento, $unidade);
        } catch (Exception $e) {
            $response->message = $e->getMessage();
        }

        return $response;
    }

    private function getAtendimento(Unidade $unidade, $id)
    {
        $atendimento = $this->em()->find('Novosga\Model\Atendimento', $id);
        if (!$atendimento || $atendimento->getServicoUnidade()->getUnidade()->getId() != $unidade->getId()) {
            throw new Exception(_('Atendimento inválido'));
        }
        if (!$atendimento) {
            throw new Exception(_('Atendimento inválido'));
        }

        return $atendimento;
    }
    
    // *********************************
    // metodos criados por w.a.s.
    // *********************************
    private function listarUsuarios( $unidadeID )
    {
      // busca todos os funcionario de uma unidade
      $sql = "SELECT to_char(ult_acesso,'DD/MM/YYYY HH24:MI') as ultimoacesso"
           . ", concat(nome,' ',sobrenome) as nomecompleto"
           . ", id"
           . ", (select concat(trim(both ' ' from sigla_senha),trim(both ' ' from to_char(num_senha_serv,'000')),' ',to_char(ate.dt_ini,'DD/MM/YYYY HH24:MI:SS'))"
           . "     from atendimentos ate"
           . "     where ate.usuario_id = usu.id"
           . "       and ate.dt_ini is not null"
           . "       and ate.dt_fim is null"
           . "  ) as em_atendimento"
           . ", case (SELECT count(*) contador "
           . "        FROM psa_intervalo "
           . "        WHERE usuario_id = usu.id"
           . "        AND final is null)"
           . "  when 0 then 'N'"
           . "  else 'S' end as em_intervalo"
           . ", ( SELECT to_char( sum(age( final, inicio)),'HH24:MI') "
           . "   FROM psa_intervalo "
           . "   WHERE usuario_id = usu.id "
           . "    AND to_char(inicio,'YYYY-MM-DD') = to_char(current_date,'YYYY-MM-DD')"
           . "  ) as tempo_total_intervalo"
           . ", ( SELECT count(*)"
           . "     FROM atendimentos"
           . "     WHERE usuario_id = usu.id"
           . "       AND to_char(dt_ini,'YYYY-MM-DD') = to_char(current_date,'YYYY-MM-DD')"
           . "       AND DT_FIM IS NOT NULL  "
           . "   ) as total_atendimento "
           . ", ( SELECT to_char( sum(age(dt_fim,dt_ini)) / count(*),'HH24:MI')"
           . "     FROM atendimentos"
           . "     WHERE usuario_id = usu.id"
           . "       AND to_char(dt_ini,'YYYY-MM-DD') = to_char(current_date,'YYYY-MM-DD')"
           . "       AND DT_FIM IS NOT NULL  "
           . "   ) as tempo_medio_atendimento "
           . ", ( SELECT to_char( sum(age(dt_fim,dt_ini)),'HH24:MI')"
           . "     FROM atendimentos"
           . "     WHERE usuario_id = usu.id"
           . "       AND to_char(dt_ini,'YYYY-MM-DD') = to_char(current_date,'YYYY-MM-DD')"
           . "       AND DT_FIM IS NOT NULL  "
           . "   ) as tempo_total_atendimento "
           . " FROM usuarios usu"
           . " WHERE status = 1 "
           . "   AND to_char(ult_acesso,'YYYY-MM-DD') = to_char(current_date,'YYYY-MM-DD')"
           . "   AND (SELECT count(*)"
           . "	 from usu_serv"
           . "	 where unidade_id = :unidade_id"
           . "         and usuario_id = usu.id"
           . "       ) > 0"
           . " ORDER BY  nome, sobrenome ";
            //  . " ORDER BY ult_acesso desc, nome, sobrenome ";
      
      $connDB = new \PDO( \Novosga\Util\PDOConexao::strConexao() );
      
      $stm = $connDB->prepare($sql);
      
      $stm->bindParam(':unidade_id', $unidadeID);
      $stm->execute();
      
      $rs = $stm->fetchAll();
      
      if( $rs ){
        $listaUsuarios = [];
        foreach ($rs as $usuario) {
         // $aAtendimentos = $this->atendimentos( $usuario['id'], substr($usuario["em_atendimento"],7,19) );
          
          $listaUsuarios[] = [
                'id' => $usuario['id'],
                'nomeCompleto' => $usuario['nomecompleto'],
                'ultimoAcesso' => $usuario['ultimoacesso'],
                'senhaAtendimento' => substr($usuario["em_atendimento"],0,6),
                'dataAtendimento' => substr($usuario["em_atendimento"],7,16),
                'emIntervalo' => ( $usuario["em_intervalo"] == 'S' ? 'S' : ''),
                'tempoTotalIntervalo' => $usuario["tempo_total_intervalo"],
                'totalAtendimento'      => $usuario["total_atendimento"],
                'tempoOcioso'           => ' ', // $aAtendimentos['tempoOcioso'],
                'mediaAtendimento'      => $usuario["tempo_medio_atendimento"],
                'tempoTotalAtendimento' => $usuario["tempo_total_atendimento"]
              ];
        }
        $this->app()->view()->set('listaUsuarios', $listaUsuarios );
      }
    }
    
   

    private function atendimentos( $usuarioID, $emAtendimento )
    { 
      $sql = "SELECT to_char(dt_ini,'YYYY-MM-DD HH24:MI:SS') as dt_ini, to_char(dt_fim,'YYYY-MM-DD HH24:MI:SS') as dt_fim"
           . " FROM atendimentos"
           . " WHERE usuario_id = :usuario_id"
           . "   AND to_char(dt_ini,'YYYY-MM-DD') = to_char(current_date,'YYYY-MM-DD')"
           . "   AND DT_FIM IS NOT NULL";
      
      $connDB = new \PDO( \Novosga\Util\PDOConexao::strConexao() );
      
      $stm = $connDB->prepare($sql);
      $stm->bindParam(':usuario_id', $usuarioID );
      $stm->execute();
      
      $rs = $stm->fetchAll();
      
      $aRet = [
        'tempoOcioso' => $this->tempoOcioso( $rs, $emAtendimento )
      ];
      
      return ( $aRet );  
    }
    
    private function tempoOcioso( $rs, $emAtendimento )
    { 
      $iniAlmoco = new \Datetime( date('Y-m-d') . ' 11:00:00');
      $fimAlmoco = new \DateTime( date('Y-m-d') . ' 14:00:00');
      
      $nOcioso = 0;
      $nAlmoco = 0;
      for( $i = 0; $i < count($rs); $i++ ){
        $dt_fim = new \DateTime( $rs[$i]['dt_fim'] );
        
        if($i+1 < count($rs) ){
          $dt_ini = new \DateTime( $rs[$i+1]['dt_ini'] );
        }
        else{
          if(strlen($emAtendimento) == 19) {
            $emAtendimento = substr($emAtendimento,6,4) .'-'.substr($emAtendimento,3,2) .'-'.substr($emAtendimento,0,2) . substr($emAtendimento,10,9);
            $dt_ini = new \DateTime($emAtendimento);
          } 
          else {
            $dt_ini = new \DateTime("now");
          }
        }
        
        $df = $dt_fim->diff($dt_ini);
        $minutos = ($df->days * 24 * 60)
                 + ( $df->h * 60)
                 + $df->i;
        
        // verificar se o intervalo está entre 11 e 14h e descontar o horario de almoço
        if( ($dt_fim >= $iniAlmoco && $dt_ini <= $fimAlmoco)
        ||  ($dt_fim <= $iniAlmoco && $dt_ini >= $fimAlmoco)      ){
           $nAlmoco += $minutos; 
        }
        
        $nOcioso += $minutos;
      }
      $nAlmoco = ($nAlmoco >= 60 ? 60 : $nAlmoco );
      $nOcioso = $nOcioso - $nAlmoco;
      
      $horas = ($nOcioso == 0 ? '' : intval($nOcioso / 60) . 'h ');
      $min = ($nOcioso == 0 ? '' : fmod($nOcioso, 60 ) . 'min.');
      
      return ( $horas . $min );  
    }
}
