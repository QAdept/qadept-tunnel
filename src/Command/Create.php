<?php

namespace Qadept\Tunnel\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class Create extends Command
{
    private $_tmpDir;

    protected function configure()
    {
        $this->setName('qatunnel')
             ->setDescription('Creates a tunnel to QAdept.com')
             ->setHelp('This command allows you to create a tunnel to run automated tests on your local websites.')
             ->addArgument('token', InputArgument::REQUIRED, 'Access token')
             ->addArgument('projects', InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                 'Names of projects for which you need to create a tunnel (separate with a space). '
                 . 'By default the command creates a tunnel for all your projects.'
             )
             ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Web server host', '127.0.0.1');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->_tmpDir = $this->_getTmpDir($output);
        if (!$this->_tmpDir) {
            exit(1);
        }
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');
        if (!$token) {
            $output->writeln('<error>Access token is required.</error>');

            // run help command
            $command = $this->getApplication()->find('help');
            $arguments = ['command_name' => 'qatunnel'];
            $command->run(new ArrayInput($arguments), $output);

            exit(1);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');
        /** @var array $projects */
        $projects = $input->getArgument('projects');

        // get SSH client command name
        $command = $this->_getSSHCommand($output);
        if (!$command) {
            return;
        }

        // send request to create a tunnel
        $url = 'http://qadept.com/system/createTunnel?token=' . $token . '&projects=' . implode(',', $projects);
        $data = json_decode(file_get_contents($url), true);
        if (!$data || !$data['ret']) {
            $error = (!empty($data['message']) ? $data['message'] : 'Can\'t connect to QAdept.com.');
            $output->writeln('<error>' . $error . '</error>');
            return;
        }

        // save key to temporary directory
        $keyPath = $this->_saveTunnelKey($data['key']);

        // build SSH command to create a tunnel
        $host = $input->getOption('host');
        $tunnels = '';
        foreach ($data['ports'] as list($srcPort, $dstPort)) {
            $tunnels .= ' -R ' . $dstPort . ':' . $host . ':' . $srcPort;
        }
        $command .= ' -N' . $tunnels . ' -i "' . $keyPath . '" ' . $data['user'] . '@' . $data['host'];

        // last chance to say to user that tunnel was opened
        $output->writeln('<info>Now following domains are available from QAdept:</info>');
        foreach ($data['urls'] as $url) {
            $output->writeln(' - ' . $url);
        }
        $output->writeln(['', 'Tunnel will be closed once you terminate this process.']);

        // create a tunnel
        $process = new Process($command);
        $process->setTimeout(0);
        $process->run();

        if (!$process->isSuccessful()) {
            $output->writeln($process->getOutput());
            $output->writeln($process->getErrorOutput());
        }

        $output->writeln('Tunnel was closed.');
    }

    private function _getTmpDir(OutputInterface $output)
    {
        // check that temporary directory is writable
        $tmpDir = sys_get_temp_dir();
        if (!is_writable($tmpDir)) {
            $output->writeln('<error>Temporary directory "' . $tmpDir . ' is not writable."</error>');
            return false;
        }

        // create subdirectory for temporary files
        $tmpDir .= DIRECTORY_SEPARATOR . 'Qadept' . DIRECTORY_SEPARATOR . 'tunnel';
        if (!file_exists($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }

        return $tmpDir;
    }

    private function _saveTunnelKey($key)
    {
        $keyPath = $nextKeyPath = $this->_tmpDir . DIRECTORY_SEPARATOR . 'tunnel_key';

        // file could be created by another user earlier
        $i = 1;
        while (file_exists($nextKeyPath) && !is_writable($nextKeyPath)) {
            // we can't rewrite it, try next file name
            $nextKeyPath = $keyPath . '_' . $i;
            $i++;
        }

        // file can be rewritten, do it
        file_put_contents($nextKeyPath, $key);
        chmod($nextKeyPath, 0600);

        return $nextKeyPath;
    }

    private function _getSSHCommand(OutputInterface $output)
    {
        $command = 'ssh';
        if ($this->_checkSSHCommand($command)) {
            return $command;
        }

        // get client for Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // try to find Git ssh client
            $paths = [
                'C:\\Program Files\\Git\\usr\\bin\\ssh.exe',
                'C:\\Program Files (x86)\\Git\\usr\\bin\\ssh.exe',
            ];
            foreach ($paths as $path) {
                $command = '"' . $path . '"';
                if (file_exists($path) && $this->_checkSSHCommand($command)) {
                    return $command;
                }
            }

            // check if we have already downloaded OpenSSH client
            $sshPath = $this->_tmpDir . DIRECTORY_SEPARATOR . 'OpenSSH-Win32' . DIRECTORY_SEPARATOR . 'ssh.exe';
            $command = '"' . $sshPath . '"';
            if (file_exists($sshPath) && $this->_checkSSHCommand($command)) {
                return $command;
            }

            // download OpenSSH client
            $output->writeln('Downloading OpenSSH client...');
            $archivePath = $this->_tmpDir . DIRECTORY_SEPARATOR . 'OpenSSH-Win32.zip';
            $file = fopen($archivePath , 'w');

            $ch = curl_init('http://github.com/PowerShell/Win32-OpenSSH/releases/download/5_30_2016/OpenSSH-Win32.zip');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_FILE, $file);

            $res = curl_exec($ch);
            curl_close($ch);
            fclose($file);

            if (!$res) {
                $output->writeln('<error>Can\'t download OpenSSH client.</error>');
                return false;
            }

            $output->writeln('<info>OpenSSH client was successfully downloaded.</info>');

            // unzip downloaded archive
            $zip = new \ZipArchive();
            if ($zip->open($archivePath) !== true) {
                $output->writeln('<error>Can\'t open downloaded archive.</error>');
                return false;
            }

            $zip->extractTo($this->_tmpDir);
            $zip->close();

            if ($this->_checkSSHCommand($command)) {
                return $command;
            }
        }

        $output->writeln('<error>SSH client was not found.</error>');

        return false;
    }

    private function _checkSSHCommand($command)
    {
        $process = new Process($command . ' -V');
        $process->run();

        return $process->isSuccessful();
    }
}