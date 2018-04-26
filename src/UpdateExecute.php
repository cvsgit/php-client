<?php
namespace Cvsgit;

use \Cvsgit\Model\ArquivoModel;
use \Cvsgit\Model\Arquivo;
use \Cvsgit\Library\Table;
use \Cvsgit\Library\Glob;
use \Cvsgit\Library\Config;
use \Exception;

class UpdateExecute
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

  private $path;
  private $configFile;
  private $commit;
  private $files;

  /**
   * @param string $data cvs command result to parse
   */
  public function __construct($path, $configFile, $commit = false, $files = array())
  {
    $this->path = $this->clearPath($path);
    $this->configFile = $configFile;
    $this->commit = $commit;
    $this->files = $files;
  }

  public function executeCommand($command)
  {
    $process = new \Symfony\Component\Process\Process($command);
    $process->start();
    $process->wait();

    $code = (int) $process->getExitCode();
    $output = explode("\n", $process->getOutput());
    $stderr = $process->getErrorOutput();

    // Verificação mair que 1 pois quando existem merge cvs retorna status 1
    if ($code > 0 &&
        (strpos($stderr, 'unknown host') !== false || strpos($stderr, '[update aborted]') !== false)
      ) {
      throw new \Exception(
        sprintf(
          'Erro %s ao executar commando %s: %s', $code, $command, $stderr
        )
      );
    }

    $process->stop();
    $process = null;

    return (object) array(
      'code' => $code,
      'output' => $output,
      'stderr' => $stderr,
    );
  }

  public function clearPath($sPath) {
    return str_replace( getcwd() . '/', '', $sPath );
  }

  public function getConfig($sConfig)
  {
    $sArquivo = $this->configFile;
    if ( !file_exists($sArquivo) ) {
      return null;
    }

    $oConfig = new Config($sArquivo);

    if ( is_null($sConfig) ) {
      return $oConfig;
    }

    return $oConfig->get($sConfig);
  }

  public function execute()
  {
    $aRetornoComandoUpdate = array();

    $aModificados = array();
    $aCriados = array();
    $aAtualizados = array();
    $aConflitos = array();
    $aAdicionados = array();
    $aRemovidos = array();
    $aRemovidosLocal = array();

    $aIgnorar = $this->getConfig('ignore') ?: array();

    // Verifica arquivos ignorados no .csvignore do projeto
    if (file_exists(getcwd() . '/.cvsignore')) {

      $oArquivoCvsIgnore = new \SplFileObject(getcwd() . '/.cvsignore');

      foreach ($oArquivoCvsIgnore as $iNumeroLinha => $sLinha) {

        $sLinha = trim($sLinha);
        if (!empty($sLinha) && !in_array($sLinha, $aIgnorar)) {
          $aIgnorar[] = $sLinha;
        }
      }
    }

    foreach ($aIgnorar as &$regex) {

      // convert glob ignore to regex
      $regex = Glob::toRegex($regex, true, false);

      if (!empty($this->path) && preg_match($regex, $this->path)) {
        return null;
      }
    }

    $command = sprintf('cvs %s update', !$this->commit ? '-qn' : '');
    if ($this->path == getcwd()) {
      $command .= " -l ";
    } else {
      $command = " $command -dRP $this->path ";
    }

    $files = array_filter(
      $this->files,
      function($path) use ($aIgnorar) {
        foreach ($aIgnorar as $regex) {
          if (preg_match($regex, $path)) {
            return false;
          }
        }
        return true;
      }
    );

    $files = implode(' ', array_map('escapeshellarg', $files));

    if (!empty($files)) {
      $command .= " " . $files . " ";
    }

    $oComando = $this->executeCommand($command);
    $aRetornoComandoUpdate = $oComando->output;
    $iStatusComandoUpdate  = $oComando->code;
    $stderr = $oComando->stderr;

    // Parse no retorno do comando cvs update
    foreach ($aRetornoComandoUpdate as $sLinhaUpdate) {

      $aLinha = explode(' ', $sLinhaUpdate);
      $oLinha = new \StdClass();
      $sTipo  = trim(array_shift($aLinha));

      /**
       * Linha não é um tipo de commit: U, ?, C...
       */
      if (!in_array($sTipo, array_keys($this->types))) {
        continue;
      }

      $oLinha->sTipo    = $sTipo;
      $oLinha->sArquivo = trim(implode(' ',$aLinha));

      // $oLinha->sArquivo = $this->clearPath($this->path . '/'. $oLinha->sArquivo);

      // add slash on directories
      if (is_dir($oLinha->sArquivo)) {
        $oLinha->sArquivo .= '/';
      }

      // Arquivo está na lista dos ignorados, pula
      foreach ($aIgnorar as $regex) {
        if (preg_match($regex, $oLinha->sArquivo)) {
          continue 2;
        }
      }

      /**
       * Lista com os erros do comando update
       */
      $aLinhasErros = explode("\n", $stderr);

      /**
       * Arquivo removido localmente
       * Percorre as linhas de erro procurando o arquivo
       *
       * @todo - arquivo com ultima versao no cvs como removido nao aparece no update
       */
      foreach ( $aLinhasErros as $sLinhaErro ) {

        // Encontrou arquivo na linh atual
        if ( strpos($sLinhaErro, "`{$oLinha->sArquivo}'") !== false ) {

          // conta a string lost na linha atual do arquivo
          if ( strpos($sLinhaErro, "lost") !== false ) {

            $sTipo = "-";
            break;
          }
        }
      }

      // Separa em arrays as modificacoes pelo tipo de commit
      switch ( $sTipo ) {

        // Novo
        case '?' :
          $aCriados[] = $oLinha->sArquivo;
        break;

        // Modificado
        case 'M' :
          $aModificados[] = $oLinha->sArquivo;
        break;

        // Conflito
        case 'C' :
          $aConflitos[] = $oLinha->sArquivo;
        break;

        // Atualizado
        case 'U' :
        case 'P' :
          $aAtualizados[] = $oLinha->sArquivo;
        break;

        // Adicionado e nao commitado
        case 'A' :
          $aAdicionados[] = $oLinha->sArquivo;
        break;

        // Removido e nao commitado
        case 'R' :
          $aRemovidos[] = $oLinha->sArquivo;
        break;

        // Removido no projeto local
        case '-' :
          $aRemovidosLocal[] = $oLinha->sArquivo;
        break;
      }
    }

    return array(
      'aModificados' => (array) $aModificados,
      'aCriados' =>(array)  $aCriados,
      'aAtualizados' => (array) $aAtualizados,
      'aConflitos' => (array) $aConflitos,
      'aAdicionados' => (array) $aAdicionados,
      'aRemovidos' => (array) $aRemovidos,
      'aRemovidosLocal' => (array) $aRemovidosLocal,
    );
  }
}
