<?php
/**
 * @copyright 2009-2018 Vanilla Forums Inc.
 * @license GPL-2.0-only
 * @since 2.8
 */

namespace Vanilla\Web\Assets;

use Vanilla\Config\IConfiguration;

/**
 * A cache buster that works based on the last deployment time.
 *
 * Settings the config value `Garden.Deployed` to the current unix timestamp on a deploy
 * will allow this cache buster to work properly.
 */
class DeploymentCacheBuster {

    /**
     * The number of seconds to wait after a deploy before switching the cache buster.
     */
    const GRACE_PERIOD = 90;

    /** @var \DateTimeInterface */
    private $currentTime;

    /** @var IConfiguration */
    private $config;

    /**
     * DeploymentCacheBuster constructor.
     *
     * @param \DateTimeInterface $currentTime
     * @param IConfiguration $config
     */
    public function __construct(\DateTimeInterface $currentTime, IConfiguration $config) {
        $this->currentTime = $currentTime;
        $this->config = $config;
    }

    /**
     * Get a cache buster string using the last deployment time.
     *
     * - Allows a grace period after the deployment before updating the query strings.
     * - Falls back to application version if there is no last deployment time.
     *
     * @return string
     */
    public function value(): string {
        $deployedTime = $this->config->get('Garden.Deployed');
        if ($deployedTime) {
            $graced = $deployedTime + self::GRACE_PERIOD;
            if ($this->currentTime->getTimestamp() >= $graced) {
                $deployedTime = $graced;
            }
            $result = dechex($deployedTime);
        } else {
            $result = APPLICATION_VERSION;
        }

        return $result;
    }
}
