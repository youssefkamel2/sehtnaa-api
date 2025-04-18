<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FirestoreService
{
    protected $client;
    protected $projectId;
    protected $accessToken;

    public function __construct()
    {
        $this->client = new Client();
        $this->projectId = env('FIREBASE_PROJECT_ID');
        $this->accessToken = $this->getAccessToken();
    }

    protected function getAccessToken()
    {
        try {
            $credentialsPath = Storage::path(env('FIREBASE_CREDENTIALS'));
            $credentials = json_decode(file_get_contents($credentialsPath), true);

            $client = new Client();
            $response = $client->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->createJwt($credentials),
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            return $data['access_token'];
        } catch (\Exception $e) {
            Log::error('Firestore token generation failed: ' . $e->getMessage());
            throw new \Exception('Failed to get Firestore access token');
        }
    }

    protected function createJwt($credentials)
    {
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/datastore',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => time() + 3600,
            'iat' => time(),
        ]);

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlClaims = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($claims));

        $signature = '';
        openssl_sign($base64UrlHeader . '.' . $base64UrlClaims, $signature, $credentials['private_key'], 'SHA256');
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        return $base64UrlHeader . '.' . $base64UrlClaims . '.' . $base64UrlSignature;
    }

    public function createDocument($collection, $documentId, $data)
    {
        $url = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents/{$collection}/{$documentId}";
    
        try {
            $fields = [];
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $fields[$key] = ['stringValue' => json_encode($value)];
                } else {
                    $fields[$key] = ['stringValue' => (string)$value];
                }
            }
    
            $response = $this->client->patch($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'fields' => $fields,
                ],
                'http_errors' => false
            ]);
    
            $responseData = json_decode($response->getBody(), true);
    
            if ($response->getStatusCode() >= 400) {
                $errorMsg = $responseData['error']['message'] ?? 'Unknown Firestore error';
                Log::channel('firestore_errors')->error('Firestore API error', [
                    'status' => $response->getStatusCode(),
                    'error' => $errorMsg,
                    'collection' => $collection,
                    'document_id' => $documentId,
                    'data_sent' => $data,
                    'response' => $responseData
                ]);
                throw new \Exception($errorMsg);
            }
    
            Log::channel('firestore')->debug('Firestore document created', [
                'collection' => $collection,
                'document_id' => $documentId,
                'data' => $data,
                'response' => $responseData
            ]);
    
            return $responseData;
        } catch (\Exception $e) {
            Log::channel('firestore_errors')->error('Firestore API request failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'collection' => $collection,
                'document_id' => $documentId,
                'data' => $data
            ]);
            throw $e;
        }
    }
}