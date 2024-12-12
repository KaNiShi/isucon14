<?php

declare(strict_types=1);

namespace IsuRide\PaymentGateway;

use Fig\Http\Message\StatusCodeInterface;
use IsuRide\Database\Model\Ride;
use RuntimeException;
use Throwable;

readonly class PostPayment
{
    /**
     * @param string $paymentGatewayURL
     * @param string $token
     * @param PostPaymentRequest $param
     * @param Ride $ride
     * @return void
     */
    public function execute(
        string $paymentGatewayURL,
        string $token,
        PostPaymentRequest $param,
        Ride $ride
    ): void {
        $b = json_encode($param);
        if ($b === false) {
            throw new RuntimeException("Failed to encode param to JSON: " . json_last_error_msg());
        }
        // 失敗したらとりあえずリトライ
        // FIXME: 社内決済マイクロサービスのインフラに異常が発生していて、同時にたくさんリクエストすると変なことになる可能性あり
        $retry = 0;
        while (true) {
            try {
                // POSTリクエストを作成
                $url = $paymentGatewayURL . "/payments";
                $headers = [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                    'Idempotency-Key: "' . $ride->id . '"',
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $b);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($response === false) {
                    $error = curl_error($ch);
                    curl_close($ch);
                    throw new RuntimeException("Curl error on POST request: " . $error);
                }
                curl_close($ch);

                if ($http_code === StatusCodeInterface::STATUS_NO_CONTENT || $http_code === StatusCodeInterface::STATUS_CONFLICT) {
                    return;
                }
            } catch (Throwable $e) {
                if ($retry < 5) {
                    $retry++;
                    usleep(100000); // 100ミリ秒
                    continue;
                } else {
                    throw $e;
                }
            }
        }
    }
}
