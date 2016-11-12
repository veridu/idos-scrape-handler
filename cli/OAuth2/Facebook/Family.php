<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */

declare(strict_types = 1);

namespace Cli\OAuth2\Facebook;

class Family extends AbstractFacebookThread {
    /**
     * {@inheritdoc}
     */
    public function execute() : bool {
        try {
            $rawEndpoint = $this->worker->getSdk()
                ->Profile($this->worker->getUserName())
                ->Raw;
            $buffer = [];
            foreach ($this->fetchAll('/me/family', 'fields=id,first_name,last_name,relationship,picture') as $json) {
                if ($json === false) {
                    break;
                }

                if (count($json)) {
                    $buffer = array_merge($buffer, $json);
                    if ($this->worker->isDryRun()) {
                        $this->worker->getLogger()->debug(
                            sprintf(
                                '[%s] Retrieved %d new items (%d total)',
                                static::class,
                                count($json),
                                count($buffer)
                            )
                        );
                        continue;
                    }

                    // Send post data to idOS API
                    $this->worker->getLogger()->debug(
                        sprintf(
                            '[%s] Uploading %d new items (%d total)',
                            static::class,
                            count($json),
                            count($buffer)
                        )
                    );
                    $rawEndpoint->upsertOne(
                        $this->worker->getSourceId(),
                        'family',
                        $buffer
                    );
                }
            }

            return true;
        } catch (\Exception $exception) {
            $this->lastError = $exception->getMessage();

            return false;
        }
    }
}
