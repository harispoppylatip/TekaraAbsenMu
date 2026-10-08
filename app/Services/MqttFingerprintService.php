<?php

namespace App\Services;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class MqttFingerprintService
{
    public function isEnabled(): bool
    {
        return (bool) config('mqtt.enabled');
    }

    public function scanTopic(): string
    {
        return config('mqtt.topic_prefix').'/+/scan';
    }

    public function registerTopic(): string
    {
        return config('mqtt.topic_prefix').'/+/register';
    }

    public function deviceTopic(string $deviceId, string $channel): string
    {
        return config('mqtt.topic_prefix').'/'.rawurlencode($deviceId).'/'.$channel;
    }

    /** @param array<string, mixed> $payload */
    public function publishCommand(string $deviceId, array $payload): void
    {
        $this->publish($this->deviceTopic($deviceId, 'command'), $payload);
    }

    /** @param array<string, mixed> $payload */
    public function publishResult(string $deviceId, array $payload): void
    {
        $this->publish($this->deviceTopic($deviceId, 'result'), $payload);
    }

    /** @param array<string, mixed> $payload */
    public function publishResponse(string $deviceId, string $channel, array $payload): void
    {
        $this->publish($this->deviceTopic($deviceId, $channel), $payload);
    }

    /** @param array<string, mixed> $payload */
    private function publish(string $topic, array $payload): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $client = $this->client('publisher-'.bin2hex(random_bytes(4)));

        try {
            $client->connect($this->connectionSettings(), true);
            $client->publish($topic, json_encode($payload, JSON_THROW_ON_ERROR), $this->qualityOfService());
        } finally {
            $client->disconnect();
        }
    }

    public function client(string $clientId): MqttClient
    {
        return new MqttClient(
            config('mqtt.host'),
            (int) config('mqtt.port'),
            $clientId,
            MqttClient::MQTT_3_1_1,
        );
    }

    public function connectionSettings(): ConnectionSettings
    {
        $settings = (new ConnectionSettings)
            ->setKeepAliveInterval((int) config('mqtt.keep_alive'))
            ->setReconnectAutomatically(false);

        if (config('mqtt.username') !== null) {
            $settings = $settings
                ->setUsername(config('mqtt.username'))
                ->setPassword(config('mqtt.password'));
        }

        if (config('mqtt.tls')) {
            $settings = $settings->setUseTls(true);
        }

        return $settings;
    }

    private function qualityOfService(): int
    {
        return max(0, min(2, (int) config('mqtt.qos')));
    }
}
