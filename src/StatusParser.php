<?php
namespace Cvsgit;

use \Cvsgit\Model\ArquivoModel;
use \Cvsgit\Library\Table;
use \Cvsgit\Model\Arquivo;

class StatusParser
{
  /**
   * Tipos de commit
   */
  public $types = array(

    /**
     * Tem arquivo no projeto mas nao no servidor, cvs add
     */
    '?' => 'Novo',

    /**
     * Tem alteracoes que nao tem no servidor
     */
    'M' => 'Modificado',

    /**
     * Conflito, e cvs tentou fazer merge
     * cvs altera arquivo colocando as diferencas
     */
    'C' => 'Conflito',

    /**
     * modificado no servidor
     * versao do servidor ? maior que a do projeto
     */
    'U' => 'Atualizado',

    /**
     * Igual U, diferenca que servidor manda um path
     */
    'P' => 'Atualizado',

    /**
     * Apos dar cvs add, arquivo pronto para ser comitado
     */
    'A' => 'Adicionado',

    /**
     * Apos remover arquivo do projeto, ira remover do servidor se for commitado
     */
    'R' => 'Arquivo marcado para remover',

    /**
     * Identifica um arquivo removido localmente
     */
    '-' => 'Removido local'

  );

  public function clearPath($path)
  {
    return str_replace(getcwd() . '/', '', $path);
  }

  public function execute(array $data, array $options = array())
  {
    $aModificados = $data['aModificados'];
    $aCriados = $data['aCriados'];
    $aAtualizados = $data['aAtualizados'];
    $aConflitos = $data['aConflitos'];
    $aAdicionados = $data['aAdicionados'];
    $aRemovidos = $data['aRemovidos'];
    $aRemovidosLocal = $data['aRemovidosLocal'];

    $lTabela      = isset($options['lTabela']) ? $options['lTabela'] : false;
    $lCriados     = isset($options['lCriados']) ? $options['lCriados'] : false;
    $lModificados = isset($options['lModificados']) ? $options['lModificados'] : false;
    $lConflitos   = isset($options['lConflitos']) ? $options['lConflitos'] : false;
    $lAtulizados  = isset($options['lAtulizados']) ? $options['lAtulizados'] : false;
    $lAdicionados = isset($options['lAdicionados']) ? $options['lAdicionados'] : false;
    $lRemovidos   = isset($options['lRemovidos']) ? $options['lRemovidos'] : false;
    $lPush        = isset($options['lPush']) ? $options['lPush'] : false;

    $aArquivosParaCommit   = array();
    $aTabelaModificacoes   = array();
    $aRetornoComandoUpdate = array();

    $sStatusOutput = "";
    $sStatusOutputTabela = "";
    $sListaArquivos = "";

    /**
     * Model do comando
     */
    $oArquivoModel = new ArquivoModel();

    /**
     * lista dos arquivos adicionados para commit
     */
    $aArquivos = $oArquivoModel->getAdicionados();

    foreach ($aArquivos as $oCommit) {
      $aArquivosParaCommit[] = $this->clearPath($oCommit->getArquivo());
    }

    /**
     * Novos
     * - arquivos criados e nao adicionados para commit
     */
    if ( $lCriados ) {

      $sArquivosCriados = '';

      foreach ($aCriados as $sArquivo) {

        if ( in_array($sArquivo, $aArquivosParaCommit) ) {
          continue;
        }

        $sArquivosCriados          .= "\n " . $sArquivo;
        $aTabelaModificacoes['?'][] = $sArquivo;
      }

      if ( !empty($sArquivosCriados) ) {

        $sStatusOutput .= "\n- Arquivos criados: ";
        $sStatusOutput .= "\n <comment>$sArquivosCriados</comment>\n";
      }
    }

    /**
     * Modificados
     * - arquivos modificados e nao adicionados para commit
     */
    if ( $lModificados ) {

      $sArquivosModificados = '';

      foreach ( $aModificados as $sArquivo ) {

        if ( in_array($sArquivo, $aArquivosParaCommit) ) {
          continue;
        }

        $sArquivosModificados .= "\n " . $sArquivo;
        $aTabelaModificacoes['M'][] = $sArquivo;
      }

      if ( !empty($sArquivosModificados) ) {

        $sStatusOutput .= "\n- Arquivos modificados: ";
        $sStatusOutput .= "\n <error>$sArquivosModificados</error>\n";
      }
    }

    /**
     * Conflitos
     * - arquivos com conflito
     */
    if ( $lConflitos ) {

      $sArquivosConflito = '';

      foreach ( $aConflitos as $sArquivo ) {


        $sArquivosConflito .= "\n " . $sArquivo;
        $aTabelaModificacoes['C'][] = $sArquivo;
      }

      if ( !empty($sArquivosConflito) ) {

        $sStatusOutput .= "\n- Arquivos com conflito: ";
        $sStatusOutput .= "\n <error>$sArquivosConflito</error>\n";
      }
    }

    /**
     * Atualizados
     * - arquivos atualizados no repository e nao local
     */
    if ( $lAtulizados ) {

      $sArquivosAtualizados = '';

      foreach ( $aAtualizados as $sArquivo ) {

        if ( in_array($sArquivo, $aArquivosParaCommit) ) {
          continue;
        }

        $sArquivosAtualizados .= "\n " . $sArquivo;
        $aTabelaModificacoes['U'][] = $sArquivo;
      }

      if ( !empty($sArquivosAtualizados) ) {

        $sStatusOutput .= "\n- Arquivos Atualizados: ";
        $sStatusOutput .= "\n <info>$sArquivosAtualizados</info>\n";
      }
    }

    /**
     * Adicionados
     * - arquivos adicionados e ainda n?o commitados
     */
    if ( $lAdicionados ) {

      $sArquivosAdicionados = '';

      foreach ( $aAdicionados as $sArquivo ) {

        if ( in_array($sArquivo, $aArquivosParaCommit) ) {
          continue;
        }

        $sArquivosAdicionados .= "\n " . $sArquivo;
        $aTabelaModificacoes['A'][] = $sArquivo;
      }

      if ( !empty($sArquivosAdicionados) ) {

        $sStatusOutput .= "\n- Arquivos adicionados: ";
        $sStatusOutput .= "\n  <info>$sArquivosAdicionados</info>\n";
      }
    }

    /**
     * Removidos
     * - arquivos removidos e ainda não commitados
     */
    if ( $lRemovidos ) {

      $sArquivosRemovidos = '';

      foreach ( $aRemovidos as $sArquivo ) {

        if ( in_array($sArquivo, $aArquivosParaCommit) ) {
          continue;
        }

        $sArquivosRemovidos        .= "\n " . $sArquivo;
        $aTabelaModificacoes['R'][] = $sArquivo;
      }

      if ( !empty($sArquivosRemovidos) ) {

        $sStatusOutput .= "\n- Arquivos marcados como removido: ";
        $sStatusOutput .= "\n <info>$sArquivosRemovidos</info>\n";
      }

      $sArquivosRemovidosLocal = '';
      foreach ($aRemovidosLocal as $sArquivo) {

        if ( in_array($sArquivo, $aArquivosParaCommit) ) {
          continue;
        }

        $sArquivosRemovidosLocal        .= "\n " . $sArquivo;
        $aTabelaModificacoes['-'][] = $sArquivo;
      }

      if ( !empty($sArquivosRemovidosLocal) ) {

        $sStatusOutput .= "\n- Arquivos removidos do projeto local: ";
        $sStatusOutput .= "\n <error>{$sArquivosRemovidosLocal}</error>\n";
      }
    }

    /**
     * Tabela
     * - Lista modificações em tableas
     */
    if ( $lTabela ) {

      $oTabela = new Table();
      $oTabela->setHeaders(array('Tipo', 'Arquivo'));

      foreach ($aTabelaModificacoes as $sTipo => $aArquivosModificacao) {

        $sTipoModificacao = "[$sTipo] " . strtr($sTipo, $this->types);

        foreach($aArquivosModificacao as $sArquivoModificacao) {
          $oTabela->addRow(array($sTipoModificacao, $sArquivoModificacao));
        }
      }

      if ( !empty($aTabelaModificacoes) ) {

        $sStatusOutputTabela .= "\nModificações nao tratadas: \n";
        $sStatusOutputTabela .= $oTabela->render();
      }

    }

    /**
     * Push
     * - arquivos para commit
     */
    if ( $lPush ) {

      /**
       * Tabela
       * - Lista arquivos prontos para commit em tabela
       */
      if ( $lTabela ) {

        $oTabelaCommit = new Table();
        $oTabelaCommit->setHeaders(array('Arquivo', 'Tag Mensagem', 'Tag Arquivo', 'Mensagem', 'Tipo'));

        foreach ($aArquivos as $oCommit) {

          $sTipo = $oCommit->getTipo();

          switch ( $oCommit->getComando() ) {

          case Arquivo::COMANDO_ADICIONAR_TAG :
            $sTipo = 'Adicionar tag';
            break;

          case Arquivo::COMANDO_REMOVER_TAG :
            $sTipo = 'Remover tag';
            break;
          }

          $oTabelaCommit->addRow(array(
            $this->clearPath($oCommit->getArquivo()), $oCommit->getTagMensagem(), $oCommit->getTagArquivo(), $oCommit->getMensagem(), $sTipo
          ));
        }

        if ( !empty($aArquivos) ) {

          $sStatusOutputTabela .= "\nArquivos prontos para commit: \n";
          $sStatusOutputTabela .= $oTabelaCommit->render();
        }
      }

      /**
       * Sem tabela
       * - Lista arquivos prontos para commit em linha
       */
      if ( !$lTabela) {

        foreach ($aArquivos as $oCommit) {
          $sListaArquivos .= "\n " . $this->clearPath($oCommit->getArquivo()) . " ";
        }

        if ( !empty($sListaArquivos) ) {

          $sStatusOutput .= "\n- Arquivos prontos para commit: ";
          $sStatusOutput .= "\n <info>$sListaArquivos</info>\n";
        }
      }

    }

    /**
     * Nenhuma modifiação encontrada
     */
    if ( empty($sStatusOutput) && empty($sStatusOutputTabela) ) {
      return null;
    }

    if ( $lTabela ) {
      $sStatusOutput = $sStatusOutputTabela;
    }

    return $sStatusOutput;
  }
}
