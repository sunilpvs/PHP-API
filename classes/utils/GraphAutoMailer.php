<?php

class AutoMail {
    private $config;
    private $accessToken;

    public function __construct() {
        $configFile = __DIR__.'/../../email.ini';       
        $this->config = parse_ini_file($configFile, true)['GraphAutoMailer'];
    }

    public function getAccessToken() {
        $url = "https://login.microsoftonline.com/{$this->config['tenantId']}/oauth2/v2.0/token";
        $data = [
            'client_id' => $this->config['clientId'],
            'scope' => 'https://graph.microsoft.com/.default',
            'client_secret' => $this->config['clientSecret'],
            'grant_type' => 'client_credentials'
        ];
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded",
                'method' => 'POST',
                'content' => http_build_query($data)
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        $response = json_decode($result, true);

        $this->accessToken = $response['access_token'];
        return $this->accessToken;
    }

    public function buildEmailBody(array $keyValueArray): string {
        //$html = "<html><body><table border='1' cellpadding='5' cellspacing='0'>";

        $html = "
        <html>
        <body style='text-align: center; padding: 20px; font-family: sans-serif;'>
            <table style='margin: 0 auto; width: 600px; border-collapse: collapse;'>
            <tr><td colspan='2'><img src=" . $this->config['BannerUrl'] . " style='width: 100%;'></td></tr><br>
            <tr><td colspan='2' style='margin-top:30px; margin-bottom:30px;'>Hi, <br><br>This is an auto generated email. Please do not respond.</td></tr>
        ";

            foreach ($keyValueArray as $key => $value) 
            {
                $html .= "<tr>
                        <td style='padding:8px; font-weight:bold;'>" . htmlspecialchars($key) . " 
                        </td>
                        <td style='padding:8px;'>" . htmlspecialchars($value) . " 
                        </td>
                        </tr>";
            }        
        $html .= "<tr><td colspan='2' style='margin-top:30px;'>Thanks, <br>" . $this->config['ByName'] . "</td></tr>
                    <tr></tr>
                    <tr><br>Powered by PVS Consultancy Services</tr>
                </table>
                </body></html>";
        return $html;
    }

    public function sendEmail($subject, array $keyValueArray, $to =[], $cc = [], $bcc = [], $attachments = []) 
    {
        $this->getAccessToken();
        $bodyContent = $this->buildEmailBody($keyValueArray);
        $email = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $bodyContent
                ],
                'toRecipients' => array_map(fn($email) => ['emailAddress' => ['address' => $email]], $to),
                'ccRecipients' => array_map(fn($email) => ['emailAddress' => ['address' => $email]], $cc),
                'bccRecipients' => array_map(fn($email) => ['emailAddress' => ['address' => $email]], $bcc),
                'attachments' => $this->formatAttachments($attachments)
            ],
            'saveToSentItems' => true
        ];

        $url = "https://graph.microsoft.com/v1.0/users/{$this->config['fromAddress']}/sendMail";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->accessToken}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($email));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new Exception("cURL error: " . curl_error($ch));
        }

        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        } else {
            // Log the response for debugging
            error_log("Graph API error ($httpCode): $response");
            return false;
        }
    }


        public function buildInfoEmailBody($greetings, $name, array $keyValueArray): string {
        //$html = "<html><body><table border='1' cellpadding='5' cellspacing='0'>";

        $html = "
        <html>
        <body style='text-align: center; padding: 20px; font-family: sans-serif;'>
            <table style='margin: 0 auto; width: 600px;  border-collapse: collapse;'>
            <tr><td colspan='2'><img src=" . $this->config['BannerUrl'] . " style='width: 100%;'></td></tr><br>
            <tr><td colspan='2' style='margin-top:30px; margin-bottom:30px;'> $greetings <br><br>This is an auto generated email. Please do not respond.</td></tr>
        ";

            foreach ($keyValueArray as $key => $value) 
            {
                $html .= "<tr>
                        <td style='padding:8px; font-weight:bold;'>" . htmlspecialchars($key) . " 
                        </td>
                        <td style='padding:8px;'>" . htmlspecialchars($value) . " 
                        </td>
                        </tr>";
            }        
        $html .= "<tr><td colspan='2' style='margin-top:30px;'>Thanks, <br>" . $name . "</td></tr>
                    <tr></tr>
                    <tr><br>Powered by PVS Consultancy Services</tr>
                </table>
                </body></html>";
        return $html;
    }

    public function sendInfoEmail($subject, $greetings, $name, array $keyValueArray, $to =[], $cc = [], $bcc = [], $attachments = []) 
    {
        $this->getAccessToken();
        $bodyContent = $this->buildInfoEmailBody($greetings, $name, $keyValueArray);
        $email = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $bodyContent
                ],
                'toRecipients' => array_map(fn($email) => ['emailAddress' => ['address' => $email]], $to),
                'ccRecipients' => array_map(fn($email) => ['emailAddress' => ['address' => $email]], $cc),
                'bccRecipients' => array_map(fn($email) => ['emailAddress' => ['address' => $email]], $bcc),
                'attachments' => $this->formatAttachments($attachments)
            ],
            'saveToSentItems' => true
        ];

        $url = "https://graph.microsoft.com/v1.0/users/{$this->config['fromAddress']}/sendMail";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->accessToken}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($email));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new Exception("cURL error: " . curl_error($ch));
        }

        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        } else {
            // Log the response for debugging
            error_log("Graph API error ($httpCode): $response");
            return false;
        }
    }

    private function formatAttachments(array $attachments): array {
        $formatted = [];
        foreach ($attachments as $filePath) {
            $contentBytes = base64_encode(file_get_contents($filePath));
            $formatted[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => basename($filePath),
                'contentBytes' => $contentBytes
            ];
        }
        return $formatted;
    }
}