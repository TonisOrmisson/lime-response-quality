<?php

use GuzzleHttp\Client;
use Yii;

require_once __DIR__ . DIRECTORY_SEPARATOR.'vendor/autoload.php';

class ExternalPostService
{
    private SurveyDynamic $response;
    private string $url;
    private string $authenticationBearerToken;
    private string $responseIdFieldName = 'token';
    private Survey $survey;
    private int $assessedItemsCount = 0;
    private float $qualityScore;

    public function __construct(SurveyDynamic $response,
            ApiConfig $config,
            Survey $survey,
            int $numberOfAssessedItems,
            float $qualityScore
    )
    {
        $this->response = $response;
        $this->survey =  $survey;
        $this->assessedItemsCount = $numberOfAssessedItems;
        $this->qualityScore = $qualityScore;
        $this->url = $config->url;
        $this->authenticationBearerToken = $config->authBearerToken;
    }

    public function run()
    {
        if(!isset($this->response->{$this->responseIdFieldName})) {
            Yii::log("Response ID field '{$this->responseIdFieldName}' not set for survey " . $this->survey->primaryKey, 'error', __METHOD__);
            return;
        }
        $data= [
            'id' => $this->response->{$this->responseIdFieldName},
            'survey' => $this->survey->primaryKey,
            'assessedItemsCount' => $this->assessedItemsCount,
            'qualityScore' => $this->qualityScore
        ];
        $this->makeRequest($data);
    }

    private function makeHeaders(){
        return [
            'Authorization' => 'Bearer ' . $this->authenticationBearerToken,
        ];
    }

    private function makeRequest(array $data)
    {
        Yii::log("Sending response data to external app,".$this->survey->primaryKey
            ." sid: response : " .$this->response->{$this->responseIdFieldName}
            , 'info', __METHOD__);
        $client = new Client();
        $headers = array_merge($this->makeHeaders(), $data);
        // Send an asynchronous request.
        $promise = $client->postAsync($this->url, $headers);

    }

}
