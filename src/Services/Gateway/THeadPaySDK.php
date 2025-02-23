<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Exception;
use function is_array;
use function json_decode;
use function json_encode;

final class THeadPaySDK
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * @throws Exception
     */
    public function pay($order): array
    {
        $params = [
            'mchid' => $this->config['theadpay_mchid'],
            'out_trade_no' => $order['trade_no'],
            'total_fee' => (string) $order['total_fee'], // in cents
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
        ];
        $params['sign'] = $this->sign($params);
        $data = json_encode($params);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->config['theadpay_url'] . "/{$this->config['theadpay_mchid']}");
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        $data = curl_exec($curl);
        curl_close($curl);

        $result = json_decode((string) $data, true);
        if (! is_array($result) || ! isset($result['status'])) {
            throw new Exception('网络连接异常: 无法连接支付网关');
        }
        if ($result['status'] !== 'success') {
            throw new Exception($result['message']);
        }

        return $result;
    }

    public function verify($params): bool
    {
        return $params['sign'] === $this->sign($params);
    }

    private function sign($params): string
    {
        unset($params['sign']);
        ksort($params);
        $data = http_build_query($params) . '&key=' . $this->config['theadpay_key'];
        return strtoupper(md5($data));
    }
}
