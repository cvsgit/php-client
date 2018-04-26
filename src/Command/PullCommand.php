<?php
namespace Cvsgit\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Terminal;
use Exception;
use \Cvsgit\Library\Shell;
use \Cvsgit\Library\Glob;
use \Cvsgit\StatusParser;

class PullCommand extends Command {

  /**
   * Configura o comando
   *
   * @access public
   * @return void
   */
  public function configure() {

    $this->setName('pull');
    $this->setDescription('Baixa atualizações do repositorio');
    $this->setHelp('Baixa atualizações do repositorio');
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

    $limit = 50;
    $command = dirname(dirname(__DIR__)) . '/bin/status.php ';

    $result = array(
      'aModificados' => array(),
      'aCriados' => array(),
      'aAtualizados' => array(),
      'aConflitos' => array(),
      'aAdicionados' => array(),
      'aRemovidos' => array(),
      'aRemovidosLocal' => array(),
    );

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
          sprintf("%s %s %s %s", $command, $data['path'], $data['config'], 'true', $data['file'])
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

        if ($curr_proc->getExitCode() == 2) {
          $processesData[] = $curr_proc->_data;
          unset($processes[$key]);
          continue;
          usleep(1000);
        }

        $curr_result = json_decode($curr_proc->getOutput(), true);

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

    $statusParser = new StatusParser();
    $statusOutput = $statusParser->execute($result, array(
      'lCriados' => false,
      'lModificados' => false,
      'lConflitos' => true,
      'lAtulizados' => true,
      'lAdicionados' => false,
      'lRemovidos' => true,
      'lPush' => false,
    ));

    $style = new OutputFormatterStyle('red');
    $oOutput->getFormatter()->setStyle('error', $style);
    $oOutput->writeln($statusOutput);
  }
}
