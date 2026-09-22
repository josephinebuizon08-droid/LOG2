<?php

require_once __DIR__ . '/../config/gemini_config.php';

function askGemini($prompt)
{
    $apiKey = GEMINI_API_KEY;
    $model  = GEMINI_MODEL;

    $url = "https://generativelanguage.googleapis.com/v1beta/models/"
         . $model
         . ":generateContent?key="
         . urlencode($apiKey);

    $data = [
        "contents" => [
            [
                "parts" => [
                    [
                        "text" => $prompt
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
    ]);

    curl_setopt(
        $ch,
        CURLOPT_POSTFIELDS,
        json_encode($data)
    );

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return [
            "success" => false,
            "error" => $error
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $result = json_decode($response, true);

    if ($httpCode >= 400) {
        return [
            "success" => false,
            "http_code" => $httpCode,
            "error" => $result
        ];
    }

    return [
        "success" => true,
        "text" => $result['$candidates'][0]['content'][0]['text'] ?? ''
    ];
}