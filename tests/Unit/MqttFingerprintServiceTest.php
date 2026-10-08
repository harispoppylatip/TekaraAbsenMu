<?php

namespace Tests\Unit;

use App\Services\MqttFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MqttFingerprintServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_topics_are_stable_and_scan_subscription_uses_mqtt_wildcard(): void
    {
        config([
            'mqtt.topic_prefix' => 'absensidikjari',
            'mqtt.enabled' => false,
        ]);

        $mqtt = app(MqttFingerprintService::class);

        $this->assertSame('absensidikjari/+/scan', $mqtt->scanTopic());
        $this->assertSame('absensidikjari/ESP32-001/command', $mqtt->deviceTopic('ESP32-001', 'command'));
        $this->assertSame('absensidikjari/ESP32-001/result', $mqtt->deviceTopic('ESP32-001', 'result'));
    }
}
