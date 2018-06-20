<?php
// declare(ticks = 1);

namespace Cvsgit\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Terminal;
use Exception;
use \Cvsgit\StatusParser;
use \Cvsgit\Model\ArquivoModel;
use \Cvsgit\Model\Arquivo;
use \Cvsgit\Library\Table;
use \Cvsgit\Library\Glob;

/**
 * StatusCommand
 *
 * @uses Command
 * @package cvs
 * @version 1.0
 */
class StatusCommand extends Command
{
  /**
   * Configura comando
   *
   * @access public
   * @return void
   */
  public function configure()
  {
    $this->setName('status');
    $this->setDescription('Lista diferenças com o repositorio');
    $this->setHelp('Lista diferenças com o repositorio');

    $this->addOption('push',     'p', InputOption::VALUE_NONE, 'Arquivos prontos para commit');
    $this->addOption('table',    't', InputOption::VALUE_NONE, 'Exibe diferenças em tabela' );
    $this->addOption('new',      '', InputOption::VALUE_NONE, 'Arquivos criados, não existem no repositorio');
    $this->addOption('modified', 'm', InputOption::VALUE_NONE, 'Arquivos modificados');
    $this->addOption('conflict', 'c', InputOption::VALUE_NONE, 'Arquivos com conflito');
    $this->addOption('update',   'u', InputOption::VALUE_NONE, 'Arquivos para atualizar, versão do repositorio é maior que a local');
    $this->addOption('added',    'a', InputOption::VALUE_NONE, 'Arquivos adicionados pelo comando "cvs add" e ainda não commitados');
    $this->addOption('removed',  'r', InputOption::VALUE_NONE, 'Arquivos removidos pelo comando "cvs rm" e ainda não commitados');
  }

  /**
   * Executa comando
   *
   * @param Object $oInput
   * @param Object $oOutput
   * @access public
   * @return void
   */
  public function execute($oInput, $oOutput)
  {
    $lTabela      = false;
    $lCriados     = false;
    $lModificados = false;
    $lConflitos   = false;
    $lAtulizados  = false;
    $lAdicionados = false;
    $lRemovidos   = false;
    $lPush        = false;

    $lPesquisaCvs = true;
    $iParametros = 0;

    foreach ( $oInput->getOptions() as $sArgumento => $sValorArgumento ) {

      if ( empty($sValorArgumento) ) {
        continue;
      }

      switch ( $sArgumento ) {

        /**
         * Exibe modificacoes em tabela
         */
        case 'table' :
          $lTabela = true;
          $iParametros++;
        break;

        /**
         * Criados
         */
        case 'new' :
          $lCriados = true;
          $iParametros++;
        break;

        /**
         * Modificados
         */
        case 'modified' :
          $lModificados = true;
          $iParametros++;
        break;

        /**
         * Conflitos
         */
        case 'conflict';
          $lConflitos = true;
          $iParametros++;
        break;

        case 'update';
          $lAtulizados = true;
          $iParametros++;
        break;

        case 'added';
          $lAdicionados = true;
          $iParametros++;
        break;

        case 'removed';
          $lRemovidos = true;
          $iParametros++;
        break;

        case 'push';
          $lPush = true;
          $iParametros++;
        break;

      }
    }

    /**
     * Passou somente parametro --push
     * - Nao pesquisa cvs(commando cvs -qn update)
     */
    if ( $iParametros == 1 && $lPush ) {
      $lPesquisaCvs = false;
    }

    /**
     * Passou parametros --push e --table
     * - Nao pesquisa cvs(commando cvs -qn update)
     */
    if ( $iParametros == 2 && $lPush && $lTabela ) {
      $lPesquisaCvs = false;
    }

    /**
     * - Nenhum parametro informado
     * - Passou somente parametro --table
     */
    if ( $iParametros == 0 || ( $lTabela && $iParametros == 1 ) ) {

      $lCriados     = true;
      $lModificados = true;
      $lConflitos   = true;
      $lAtulizados  = true;
      $lAdicionados = true;
      $lRemovidos   = true;
      $lPush        = true;
    }

    $result = array(
      'aModificados' => array(),
      'aCriados' => array(),
      'aAtualizados' => array(),
      'aConflitos' => array(),
      'aAdicionados' => array(),
      'aRemovidos' => array(),
      'aRemovidosLocal' => array(),
    );

    /**
     * Pesquisa modificacoes no cvs apenas se:
     *  - nenhum parametro informado
     *  - não passou somente parametro push
     */
    if ( $lPesquisaCvs ) {

      $directories = array_filter(glob(getcwd() . '/*' , GLOB_ONLYDIR), function($path) {
        return $path != getcwd() . '/CVS';
      });

      array_unshift($directories, getcwd());

      $processes = array();
      $processesData = array();
      $configFile = CONFIG_DIR . $this->getApplication()->getModel()->getRepositorio() . '_config.json';

      $aIgnorar = $this->getApplication()->getConfig('ignore') ?: array();

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

      // convert glob ignore to regex
      foreach ($aIgnorar as &$regex) {
        $regex = Glob::toRegex($regex, true, false);
      }

      foreach ($directories as $curr_dir) {

        $curr_dir = $this->getApplication()->clearPath($curr_dir);

        foreach ($aIgnorar as $regex) {
          if (preg_match($regex, $curr_dir)) {
            continue 2;
          }
        }

        $processesData[] = array(
          'path' => $curr_dir,
          'config' => $configFile,
          'file' => null,
        );
      }

      $errors = array();
      $limit = 5;
      $command = dirname(dirname(__DIR__)) . '/bin/status.php ';

      ProgressBar::setFormatDefinition('custom', ' %percent%% [%bar%] ');

      $progressBar = new ProgressBar($oOutput, count($processesData));

      // the finished part of the bar
      $progressBar->setBarCharacter('<comment>=</comment>');

      // the unfinished part of the bar
      $progressBar->setEmptyBarCharacter(' ');

      // the progress character
      $progressBar->setProgressCharacter('>');

      // the bar width
      $termianl = new Terminal();
      $progressBar->setBarWidth($termianl->getWidth());

      $progressBar->setFormat('custom');
      // $progressBar->setRedrawFrequency(1);
      $progressBar->start();

      while(true) {

        if (empty($processesData) && empty($processes)) {
          break;
        }

        foreach ($processesData as $key => $data) {

          if (count($processes) > $limit) {
            break;
          }

          $process = new \Symfony\Component\Process\Process(
            sprintf("%s %s %s %s", $command, $data['path'], $data['config'], 'false', $data['file'])
          );

          try {

            @$process->start();
            $process->_data = $data;
            $processes[] = $process;

          } catch (\Exception $e) {
            $this->getApplication()->displayError(
              "Erro ao iniciar processo: " . $e->getMessage(),
              $oOutput
            );
          }

          unset($processesData[$key]);
        }

        foreach ($processes as $key => $curr_proc) {

          if (!$curr_proc->isTerminated()) {
            continue;
          }

          // if ($curr_proc->getExitCode() == 2) {
          //   $processesData[] = $curr_proc->_data;
          //   unset($processes[$key]);
          //   continue;
          //   usleep(1000);
          // }

          $curr_result = null;

          if ($curr_proc->getExitCode() > 1) {
            $errors[$curr_proc->_data['path']] = $curr_proc->getErrorOutput();
          } else {
            $curr_result = json_decode($curr_proc->getOutput(), true);
          }

          if (!empty($curr_result)) {
            foreach ($curr_result as $curr_result_key => $values) {
              foreach ($values as $value) {
                $result[$curr_result_key][] = $value;
              }
            }
          }

          $progressBar->advance();
          unset($processes[$key]);
        }

        usleep(1000);
      }

      $progressBar->finish();
      $progressBar->clear();
    }

    $statusParser = new StatusParser();
    $statusOutput = $statusParser->execute($result, array(
      'lCriados' => $lCriados,
      'lModificados' => $lModificados,
      'lConflitos' => $lConflitos,
      'lAtulizados' => $lAtulizados,
      'lAdicionados' => $lAdicionados,
      'lRemovidos' => $lRemovidos,
      'lPush' => $lPush,
      'lTabela' => $lTabela,
    ));

    $style = new OutputFormatterStyle('red');
    $oOutput->getFormatter()->setStyle('error', $style);
    $oOutput->writeln($statusOutput);

    if (!empty($errors)) {
      foreach ($errors as $path => $error) {
        $oOutput->writeln(sprintf(' - <error>Erro ao atualizar: %s</error>', $path));
        $oOutput->writeln(sprintf('   <error>%s</error>', $error));
      }
      $oOutput->writeln("");
    }
  }
}
