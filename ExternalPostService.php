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
    private bool $sendApiRequest = true;
    private ResponseQualityChecker $checker;

    public function __construct(SurveyDynamic $response,
            ApiConfig $config,
            ResponseQualityChecker $responseQualityChecker)
    {
        $this->checker = $responseQualityChecker;
        $this->response = $response;
        $this->survey =  $responseQualityChecker->getSurvey();
        $this->assessedItemsCount = $responseQualityChecker->getTotalSubQuestions();
        $this->qualityScore = $responseQualityChecker->getTotalQuality();
        $this->url = $config->url;
        $this->authenticationBearerToken = $config->authBearerToken;
        $this->responseIdFieldName = $responseQualityChecker->responseIdQuestionFieldName();
        $this->sendApiRequest = $responseQualityChecker->isSendApiRequest();
    }

    public function run()
    {
        if(!isset($this->response->{$this->responseIdFieldName})) {
            Yii::log("Response ID field '{$this->responseIdFieldName}' not available for survey " . $this->survey->primaryKey, 'error', __METHOD__);
            return;
        }
        $data= [
            'id' => $this->response->{$this->responseIdFieldName},
            'survey' => $this->survey->primaryKey,
            'assessedItemsCount' => $this->assessedItemsCount,
            'qualityScore' => $this->qualityScore,
            'straightLining' => [
                'items' => $this->checker->getStraightLiningItemsCount(),
                'quality' => $this->checker->getStraightLineQuality(),
            ],
            'dontKnows' => [
                'items' => $this->checker->getDontKnowItemsCount(),
                'quality' => $this->checker->getDontKnowQuality(),
            ],
            'timing' => [
                'items' => null,
                'quality' => $this->checker->getTimingQuality(),
            ],
        ];
        $this->makeRequest($data);
    }

    private function makeHeaders(){
        return [
            'Authorization' => 'Bearer ' . $this->authenticationBearerToken,
            'Accept'        => 'application/json',
        ];
    }

    private function makeRequest(array $data)
    {
        Yii::log($this->authenticationBearerToken, 'info', __METHOD__);
        Yii::log("Sending response data to external app,".$this->survey->primaryKey
            ." sid: response : " .$this->response->{$this->responseIdFieldName}
            , 'info', __METHOD__);
        $client = new Client();
        $headers = $this->makeHeaders();
        $options = [
            'headers' => $headers,
            'form_params' => $data,
            'timeout' => 2,
            'connect_timeout' => 2,
        ];

        try {
            Yii::log("Posting reponse data to api", 'trace', __METHOD__);
            $result = $client->post($this->url, $options);
            if($result->getStatusCode() === 200) {
                Yii::log("Response data sent to external app, sid:".$this->survey->primaryKey
                    ." response : " .$this->response->{$this->responseIdFieldName}
                    , 'info', __METHOD__);
                Yii::log("response:" . json_encode($result->getBody()->getContents()), 'trace', __METHOD__);
            } else {
                Yii::log("Response data sending failed for sid:".$this->survey->primaryKey
                    ." response : " .$this->response->{$this->responseIdFieldName}
                    . " error: " .$result->getBody()->getContents()
                    , 'error', __METHOD__);
            }
            return;
        } catch (\Throwable $th) {
            Yii::log("Error sending response data to external app,sid:".$this->survey->primaryKey
                ." response: " .$this->response->{$this->responseIdFieldName}." error:" . $th->getMessage()
                , 'error', __METHOD__);
            return;
        }
        Yii::log("Should not br here", 'error', __METHOD__);

    }

}
