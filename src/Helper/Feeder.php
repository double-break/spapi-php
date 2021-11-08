<?php
/*
 *  @author MST1122
 *  @email: sikandartariq25@gmail.com
 */

namespace DoubleBreak\Spapi\Helper;


use DoubleBreak\Spapi\ASECryptoStream;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

class Feeder
{
    /**
     * @param $payload : Response from createFeedDocument Function. e.g.: response['payload']
     * @param $contentType : Content type used during createFeedDocument function call.
     * @param $feedContentFilePath : Path to file that contain data to be uploaded.
     * @return string
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function uploadFeedDocument($payload, $contentType, $feedContentFilePath)
    {
        $key = null;
        $initializationVector = null;
        $feedUploadUrl = $payload['url'];

        // check if encryption in required
        if (isset($payload['encryptionDetails'])) {
            $key = $payload['encryptionDetails']['key'];
            $initializationVector = $payload['encryptionDetails']['initializationVector'];

            // base64 decode before using in encryption
            $initializationVector = base64_decode($initializationVector, true);
            $key = base64_decode($key, true);
        }

        // get file to upload
        $fileResourceType = gettype($feedContentFilePath);

        // resource or string ? make it to a string
        if ($fileResourceType == 'resource') {
            $file_content = stream_get_contents($feedContentFilePath);
        } else {
            $file_content = file_get_contents($feedContentFilePath);
        }

        // utf8 !
        $file_content = utf8_encode($file_content);

        if (!is_null($key)) {
            // encrypt string and get value as base64 encoded string
            $file_content = ASECryptoStream::encrypt($file_content, $key, $initializationVector);
        }

        // my http client
        $client = new Client(['exceptions' => false]);

        $request = new Request(
        // PUT!
            'PUT',
            // predefined url
            $feedUploadUrl,
            // content type equal to content type from response createFeedDocument-operation
            array('Content-Type' => $contentType),
            // resource File
            $file_content
        );

        $response = $client->send($request);
        $HTTPStatusCode = $response->getStatusCode();

        if ($HTTPStatusCode == 200) {
            return 'Done';
        } else {
            return $response->getBody()->getContents();
        }
    }

    /**
     * @param $payload : Response from getFeedDocument Function. e.g.: response['payload']
     * @return array : Feed Processing Report.
     */
    public function downloadFeedProcessingReport($payload)
    {
        $key = null;
        $initializationVector = null;
        $feedUploadUrl = $payload['url'];

        // check if decryption in required
        if (isset($payload['encryptionDetails'])) {
            $key = $payload['encryptionDetails']['key'];
            $initializationVector = $payload['encryptionDetails']['initializationVector'];

            // base64 decode before using in encryption
            $initializationVector = base64_decode($initializationVector, true);
            $key = base64_decode($key, true);
        }

        $feedDownloadUrl = $payload['url'];

        if (!is_null($key)) {
            $feed_processing_report_content = ASECryptoStream::decrypt(file_get_contents($feedDownloadUrl), $key, $initializationVector);
        } else {
            $feed_processing_report_content = file_get_contents($feedDownloadUrl);
        }

        if(isset($payload['compressionAlgorithm']) && $payload['compressionAlgorithm']=='GZIP') {
            $feed_processing_report_content = gzdecode($feed_processing_report_content);
        }

        // check if report content is json encoded or not
        if ($this->isJson($feed_processing_report_content) == true) {
            $json = $feed_processing_report_content;
        } else {
            $feed_processing_report_content = preg_replace('/\s+/S', " ", $feed_processing_report_content);
            $xml = simplexml_load_string($feed_processing_report_content);
            $json = json_encode($xml);
        }

        return json_decode($json, TRUE);
    }

    public function isJson($string) {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
