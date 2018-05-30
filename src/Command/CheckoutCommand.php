<?php

namespace Cvsgit\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Exception;

/**
 * @package CVS
 */
class CheckoutCommand extends Command {

  /**
   * Carrega as configurações e adiciona os helpers
   */
  public function configure() {

    $this->setName('checkout');
    $this->setDescription('Checkout em um ou mais arquivo(s)');
    $this->setHelp('Checkout em um ou mais arquivo(s)');
    $this->addArgument('arquivos', InputArgument::IS_ARRAY, 'Arquivos para checkout');
  }

  /**
   * Executa o comando
   *
   * @param \Symfony\Component\Console\Input\InputInterface   $oInput
   * @param \Symfony\Component\Console\Output\OutputInterface $oOutput
   * @return int|void
   * @throws Exception
   */
  public function execute($oInput, $oOutput) {

    $this->oInput  = $oInput;
    $this->oOutput = $oOutput;

    $aArquivos = $this->oInput->getArgument('arquivos');

    if (empty($aArquivos)) {
      throw new Exception('Nenhum arquivo informado.');
    }

    $aCommandos = array();

    foreach ($aArquivos as $sArquivo) {
      $aCommandos[$sArquivo][] = 'cvs update -C ' . escapeshellarg($sArquivo);
    }

    $this->oOutput->writeln('Comandos:');

    foreach ($aCommandos as $sArquivo => $aCommando) {
      foreach ($aCommando as $sCommando) {
        $this->oOutput->writeln(" -- $sCommando");
      }
    }

    $this->oOutput->writeln('');

    $helper = $this->getHelper('question');
    $question = new Question('Executar checkout?: (s/N): ');

    $sConfirma = $helper->ask($this->oInput, $this->oOutput, $question);

    if (strtoupper($sConfirma) != 'S') {
        return 0;
    }

    $this->oOutput->writeln('');

    foreach ($aCommandos as $sArquivo => $aCommando) {

      foreach ($aCommando as $sCommando) {

        exec($sCommando . ' 2> /tmp/cvsgit_last_error', $aRetorno, $iStatus);

        if ($iStatus > 0) {

          $this->getApplication()->displayError("Erro ao executar comando: $sCommando", $this->oOutput);
          return $iStatus;
        }

      }

      $this->oOutput->writeln(" -- $sArquivo atualizado");
    }

    $this->oOutput->writeln('');
  }

}
