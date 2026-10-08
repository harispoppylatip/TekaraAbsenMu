<?php

return [
    'enabled' => (bool) env('MQTT_ENABLED', true),
    'host' => env('MQTT_HOST', '127.0.0.1'),
    'port' => (int) env('MQTT_PORT', 1883),
    'username' => env('MQTT_USERNAME'),
    'password' => env('MQTT_PASSWORD'),
    'tls' => (bool) env('MQTT_TLS', false),
    'topic_prefix' => trim(env('MQTT_TOPIC_PREFIX', 'absensidikjari'), '/'),
    'client_id' => env('MQTT_CLIENT_ID', 'absensidikjari-server'),
    'qos' => (int) env('MQTT_QOS', 1),
    'keep_alive' => (int) env('MQTT_KEEP_ALIVE', 30),
];
