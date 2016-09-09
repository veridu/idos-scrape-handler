<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */

declare(strict_types = 1);

namespace Cli\Command;

use Cli\HandlerFactory;
use Cli\OAuthFactory;
use Cli\Utils\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Veridu\idOS\SDK;

/**
 * Command definition for Scraper Daemon.
 */
class Daemon extends Command {
    /**
     * Command configuration.
     *
     * @return void
     */
    protected function configure() {
        $this
            ->setName('scrape:daemon')
            ->setDescription('idOS Scrape - Daemon')
            ->addArgument(
                'providerName',
                InputArgument::REQUIRED,
                'Provider name'
            )
            ->addArgument(
                'serverList',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'Gearman server host list (separate values by space)'
            );
    }

    /**
     * Command execution.
     *
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $outpput
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $logger = new Logger();

        $logger->debug('Initializing idOS Scrape Handler Daemon');

        // Provider setup
        $providerName = $input->getArgument('providerName');

        // Server List setup
        $servers = $input->getArgument('serverList');

        $gearman = new \GearmanWorker();
        foreach ($servers as $server) {
            if (strpos($server, ':') === false) {
                $logger->debug(sprintf('Adding Gearman Server: %s', $server));
                $gearman->addServer($server);
            } else {
                $server    = explode(':', $server);
                $server[1] = intval($server[1]);
                $logger->debug(sprintf('Adding Gearman Server: %s:%d', $server[0], $server[1]));
                $gearman->addServer($server[0], $server[1]);
            }
        }

        // Run the worker in non-blocking mode
        $gearman->addOptions(\GEARMAN_WORKER_NON_BLOCKING);

        // 1 second I/O timeout
        $gearman->setTimeout(1000);

        // Scrape Handler Factory
        $factory = new HandlerFactory(
            new OAuthFactory(),
            [
                'Linkedin' => 'Cli\\Handler\\LinkedIn',
                'Paypal'   => 'Cli\\Handler\\PayPal'
            ]
        );

        if (! $factory->check($providerName)) {
            throw new \RuntimeException(sprintf('Invalid provider "%s".', $providerName));
        }

        // idOS SDK Factory
        $sdkFactory = new SDK\Factory(
            __HNDKEY__,
            __HNDSEC__
        );

        $functionName = sprintf('%s-scrape', strtolower($providerName));

        $logger->debug(sprintf('Registering Worker Function "%s"', $functionName));

        $gearman->addFunction(
            $functionName,
            function (\GearmanJob $job) use ($logger, $factory, $providerName, $sdkFactory) {
                $logger->debug('Got a new job!');
                $jobData = json_decode($job->workload(), true);
                if ($jobData === null) {
                    $logger->debug('Invalid Job Workload!');
                    $job->sendComplete('invalid');

                    return;
                }

                $provider = $factory->create(
                    $logger,
                    $providerName,
                    isset($jobData['accessToken']) ? $jobData['accessToken'] : '',
                    isset($jobData['tokenSecret']) ? $jobData['tokenSecret'] : '',
                    isset($jobData['appKey']) ? $jobData['appKey'] : '',
                    isset($jobData['appSecret']) ? $jobData['appSecret'] : '',
                    isset($jobData['apiVersion']) ? $jobData['apiVersion'] : ''
                );

                $data = $provider->handle();

                $sdk = $sdkFactory->create($jobData['pubKey']);
                foreach ($data as $collection => $content) {
                    $logger->debug(
                        sprintf(
                            'Sending data for "%s" (%d bytes)',
                            $collection,
                            strlen($content)
                        )
                    );
                    $sdk->raw()->createNew(
                        $jobData['userName'],
                        $jobData['sourceId'],
                        $collection,
                        $content
                    );
                }

                $logger->debug('Job done!');
                $job->sendComplete('ok');
            }
        );

        $functionName = sprintf('%s-ping', strtolower($providerName));

        $logger->debug(sprintf('Registering Ping Function "%s"', $functionName));

        // Register Thread's Ping Function
        $gearman->addFunction(
            $functionName,
            function (\GearmanJob $job) use ($logger) {
                $logger->debug('Ping!');

                return 'pong';
            }
        );

        $logger->debug('Entering Gearman Worker Loop');

        // Gearman's Loop
        while ($gearman->work()
                || ($gearman->returnCode() == \GEARMAN_IO_WAIT)
                || ($gearman->returnCode() == \GEARMAN_NO_JOBS)
                || ($gearman->returnCode() == \GEARMAN_TIMEOUT)
        ) {
            if ($gearman->returnCode() == \GEARMAN_SUCCESS) {
                continue;
            }

            if (! @$gearman->wait()) {
                if ($gearman->returnCode() == \GEARMAN_NO_ACTIVE_FDS) {
                    // No server connection, sleep before reconnect
                    $logger->debug('No active server, sleep before retry');
                    sleep(5);
                    continue;
                }

                if ($gearman->returnCode() == \GEARMAN_TIMEOUT) {
                    // Job wait timeout, sleep before retry
                    sleep(1);
                    continue;
                }
            }
        }

        $logger->debug('Leaving Gearman Worker Loop');
    }
}
