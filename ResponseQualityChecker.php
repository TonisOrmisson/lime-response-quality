<?php


/**
 * @author Tõnis Ormisson <tonis@andmemasin.eu>
 */
class ResponseQualityChecker extends PluginBase
{
    use AppTrait;

    protected LSYii_Application $app;

    protected $storage = 'DbStorage';
    static protected $description = 'Response Quality Checker';
    static protected $name = 'Response Quality Checker';

    /** @var Survey $survey */
    private Survey $survey;
    private int $totalSubQuestions = 0;
    private array $questions = [];
    /** @var null|Question|bool */
    private $targetQuestion = null;
    /** @var null|Question|bool */
    private $appNameQuestion = null;
    protected $settings = [];
    private bool $sendApiRequest = true;

    public function __construct(\LimeSurvey\PluginManager\PluginManager $manager, $id)
    {
        parent::__construct($manager, $id);
        $this->defaults();

    }


    /* Register plugin on events*/
    public function init() {
        Yii::log("Initializing plugin", "trace", __METHOD__);


        $this->subscribe('afterFindSurvey');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('beforeToolsMenuRender');
        $this->subscribe('newSurveySettings');

    }


    public function afterSurveyComplete()
    {
        $responseId = $this->event->get('responseId');
        Yii::log('afterSurveyComplete on response:' . $responseId, 'trace', __METHOD__);

        $response = SurveyDynamic::model($this->survey->primaryKey)->findByPk($responseId);
        if(!($response instanceof SurveyDynamic)) {
            Yii::log('response not found' , 'info', __METHOD__);
            return;
        }
        Yii::log('found response:' . $response->id, 'trace', __METHOD__);

        $this->loadSurvey();
        if(!$this->enabled()) {
            Yii::log('plugin disabled' , 'trace', __METHOD__);
            return;
        }
        $this->questions = $this->checkableQuestions();
        $this->checkResponse($response);

    }


    public function checkResponse(SurveyDynamic $response) {
        $questions = $this->questions;
        $this->totalSubQuestions = 0;
        if(count($questions) == 0) {
            Yii::log('no questions found' , 'trace', __METHOD__);
            return;
        }
        $totalQuality = 1.0;
        $questionQualities = [];
        Yii::log("found ".count($questions)." questions for quality check " , 'info', __METHOD__);
        foreach ($questions as $question) {
            $questionQuality = $this->checkQuestionQuality($question, $response);
            if($questionQuality->getItems() > 0) {
                $questionQualities[] = $questionQuality;
                Yii::log($question->title . " [".round($questionQuality->getQuality() *100,1)."%] ". $question->title , 'trace', __METHOD__);
            }
        }
        if($this->totalSubQuestions > 0) {
            $totalQuality = 0.0;
            foreach ($questionQualities as $questionQuality) {
                $itemWeight = $questionQuality->getItems() / $this->totalSubQuestions;
                $itemQuality = $questionQuality->getQuality() * $itemWeight;
                $totalQuality += $itemQuality;
            }
        }

        Yii::log("total SubQuestions " . $this->totalSubQuestions , 'info', __METHOD__);
        Yii::log("totalQuality " .round($totalQuality * 100, 0). "%" , 'info', __METHOD__);
        $this->saveResult($totalQuality, $response);
        if($this->isTrashResult($totalQuality) && $this->unSubmitEnabled()) {
            $this->unSubmitResponse($response);
        }

        if($this->sendApiRequest) {
            $this->sendResultToApp($response, $this->totalSubQuestions, $totalQuality);
        }

    }




    public function checkStraightLining(Question $question, SurveyDynamic $response) : QualityResult
    {
        Yii::log('checkStraightLining:sid:'. $this->survey->primaryKey . ":response:" . $response->id .":" , 'trace', __METHOD__);
        $subQuestions = $question->subquestions;
        $result = new QualityResult();
        if(count($subQuestions) == 0) {
            Yii::log('no subQuestions found' , 'trace', __METHOD__);
            return $result;
        }
        Yii::log("found ".count($subQuestions)." subQuestions for quality check " , 'trace', __METHOD__);
        $answers = [];
        $sgq = $question->getBasicFieldName();
        foreach ($subQuestions as $subQuestion) {
            $sgqa = $sgq.$subQuestion->title;
            $answer = $response->$sgqa;
            if($answer == '' or $answer == null) {
                continue;
            }
            $this->totalSubQuestions++;
            $result->addItems(1);
            $answers[] = $answer;
            //Yii::log("checking $sgqa:". $answer  , 'info', __METHOD__);
        }
        if(count($answers) == 0) {
            Yii::log('no answers found' , 'trace', __METHOD__);
            return $result;
        }

        Yii::log("answers ". json_encode($answers)  , 'trace', __METHOD__);

        $counts = array_count_values($answers);
        arsort($counts);
        $mostCheckedAnswer = current( $counts);
        $overlapPct = $mostCheckedAnswer / count($answers);
        if($overlapPct < 0.5 or $mostCheckedAnswer < 4) {
            $result->setQuality(1.0);
        } else {
            $result->setQuality(1- $overlapPct);
        }
        Yii::log("counts ". json_encode($counts)  , 'trace', __METHOD__);
        Yii::log("most checked ". $mostCheckedAnswer  , 'trace', __METHOD__);
        Yii::log("overlapping % ". round($overlapPct * 100, 0). "%"  , 'trace', __METHOD__);
        return $result;
    }



    public function targetQuestion() : ?Question
    {
        if($this->targetQuestion !== null) {
            return $this->targetQuestion;
        }
        $targetQuestionName = $this->settingValue('targetQuestion');
        Yii::log('looking for target question ' . $targetQuestionName, 'trace', __METHOD__);
        $targetQuestion = $this->findQuestionByName($targetQuestionName);

        if($targetQuestion === null) {
            $this->targetQuestion = null;
            Yii::log("target question $targetQuestionName not found"  , 'trace', __METHOD__);
            return null;
        }

        Yii::log('found target question ' . $targetQuestionName, 'trace', __METHOD__);
        $this->targetQuestion = $targetQuestion;
        return $targetQuestion;

    }

    public function appNameQuestion() : ?Question
    {
        if($this->appNameQuestion !== null) {
            return $this->appNameQuestion;
        }
        $appNameQuestionName = $this->settingValue('externalAppNameQuestion');
        Yii::log('looking for app-name question ' . $appNameQuestionName, 'trace', __METHOD__);
        $appNameQuestion = $this->findQuestionByName($appNameQuestionName);
        if($appNameQuestion === null) {
            $this->appNameQuestion = null;
            Yii::log("app-name question $appNameQuestionName not found"  , 'trace', __METHOD__);
            return null;
        }

        Yii::log('found app-name question ' . $appNameQuestionName, 'trace', __METHOD__);
        $this->appNameQuestion = $appNameQuestion;
        return $appNameQuestion;

    }

    public function afterFindSurvey() {
        $this->loadSurvey();
    }

    public function enabled(): bool
    {
        return boolval($this->get("enabled", 'Survey', $this->survey->primaryKey));
    }


    public function actionIndex($sid)
    {
        $this->beforeAction($sid);
        Yii::log(json_encode($this->targetQuestion->attributes), 'trace', __METHOD__);
        $this->sendApiRequest = false;
        /** @var LSYii_Application $app */
        $app = Yii::app();
        $request = $app->request;

        if ($request->isPostRequest) {
            $this->sendApiRequest = boolval($request->getPost('sendApiRequest'));
            Yii::log('sendApiRequest: ' . strval($this->sendApiRequest), 'trace', __METHOD__);
            $this->checkWholeSurvey();
        }

        return $this->renderPartial('index',
            [
                'survey' => $this->survey,
                'targetQuestion' => $this->targetQuestion,
                'appNameQuestion' => $this->appNameQuestion,
                'targetQuestionName' => $this->settingValue('targetQuestion'),
                'responseIdFieldName' => $this->responseIdFieldName(),
                'externalAppNameQuestionName' => $this->externalAppNameQuestionName()
            ],
            true
        );
    }



    /**
     * This event is fired by the administration panel to gather extra settings
     * available for a survey.
     * The plugin should return setting meta data.
     */
    public function beforeSurveySettings()
    {
        $this->loadSurveySettings();
    }

    public function newSurveySettings()
    {
        $event = $this->event;

        foreach ($event->get('settings') as $name => $value) {
            $this->set($name, $value, 'Survey', $event->get('survey'));
        }
    }

    public function getSurvey() : Survey
    {
        return $this->survey;
    }


    private function sendResultToApp(SurveyDynamic $response, int $subQuestionsCount, float $qualityScore) : bool
    {
        $externalAppNameQuestionName = $this->externalAppNameQuestionName();
        if($externalAppNameQuestionName === null) {
            return false;
        }
        $appNameQuestion = $this->findQuestionByName($externalAppNameQuestionName);
        if($appNameQuestion === null) {
            Yii::log("could not find question $externalAppNameQuestionName in survey:" . $this->survey->primaryKey, 'error', __METHOD__);
            return false;
        }
        $fieldName = $appNameQuestion->getBasicFieldName();

        $appName = $response->$fieldName;
        if(empty($appName)){
            Yii::log("no app name value found for resonse {$response->id} in survey:" . $this->survey->primaryKey, 'info', __METHOD__);
            return false;
        }

        $apiConfig = $this->apiConfig($appName);

        if($apiConfig === null) {
            Yii::log("no api config found for app $appName in survey:" . $this->survey->primaryKey, 'info', __METHOD__);
            return false;
        }

        Yii::log("Sending response quality to app $appName in survey:" . $this->survey->primaryKey, 'info', __METHOD__);
        $postService = new ExternalPostService(
            $response,
            $apiConfig,
            $this->survey,
            $subQuestionsCount,
            $qualityScore,
            $this->sendApiRequest,
            $this->responseIdQuestionFieldName()
        );
        $postService->run();
        return true;
    }




    private function defaults() : void
    {
        if(count($this->settings)> 0 ) {
            return;
        }

        Yii::log("Setting defaults", "trace", __METHOD__);

        $this->settings = [
            'enabled' => [
                'type' => 'boolean',
                'label' => 'Enable plugin for survey',
                'default'=>false,
            ],
            'threshold' => [
                'type' => 'float',
                'label' => 'The quality threshold to initiate kick-out of response',
                'default'=>0.3,
            ],
            'unSubmitEnabled' => [
                'type' => 'boolean',
                'label' => 'Un-submit response when quality threshold is reached',
                'default'=>false,
            ],

            'targetQuestion' => [
                'type' => 'string',
                'label' => 'The name of the question to store the quality result',
                'default'=>'quality',
            ],
            'responseIdFieldName' => [
                'type' => 'string',
                'label' => 'The name of the question/or attribute of response id for sending to external system. Could be a question name or a field name eg token',
                'default'=>'token',
            ],
            'externalAppNameQuestion' =>[
                'type' => 'string',
                'label' => 'The name of the question that sores the relevant app-name of each response. The app name should match with the app name in external app configuration to be able to send quality results to the external application.',
                'default'=>'appname',
            ],
            'external-apps' => [
                'type' => 'json',
                'label' => 'External apps config to send quality results to as json. An application config must have an '
                            . 'format like this: { "appname": { "url": "http://example.com", "auth-token": "secret-token" } }',
                'default' => json_encode([
                    'app1' => [
                        'url' => 'https://example.com/app1',
                        'auth-token' => 'secret',
                    ],
                    'app2' => [
                        'url' => 'https://example.com/app2',
                        'auth-token' => 'secret2',
                    ],
                ]),
            ],

        ];
        Yii::log("Settings:" . json_encode($this->settings), "trace", __METHOD__);

    }

    private function saveResult(float $totalQuality, SurveyDynamic $response) : void
    {
        $totalQuality = round($totalQuality, 3);
        $targetQuestion = $this->targetQuestion();
        if(!($targetQuestion instanceof Question)) {
            Yii::log('target question NOT found, not able to save result' . json_encode($targetQuestion->attributes) , 'trace', __METHOD__);
            return;
        }
        Yii::log('target question found' . json_encode($targetQuestion->attributes) , 'trace', __METHOD__);

        $sgqa = $targetQuestion->getBasicFieldName();
        /** @var CDbConnection $db */
        $db = Yii::app()->db;
        $rows = $db->createCommand()
            ->update(
                $this->survey->getResponsesTableName(),
                [$sgqa => $totalQuality],
                'id='.$response->id
            );
        Yii::log("Saved $rows records result " . $totalQuality , 'trace', __METHOD__);

    }

    private function unSubmitResponse(SurveyDynamic $response) : void
    {
        /** @var CDbConnection $db */
        $db = Yii::app()->db;
        $rows = $db->createCommand()
            ->update(
                $this->survey->getResponsesTableName(),
                ['submitdate' => new CDbExpression('null')],
                'id='.$response->id
            );
        if($rows == 1) {
            Yii::log("UnSubmitted response " . $response->id , 'info', __METHOD__);
        }
    }


    public function beforeToolsMenuRender() {
        $event = $this->getEvent();

        /** @var array $menuItems */
        $menuItems = $event->get('menuItems');
        $this->survey = Survey::model()->findByPk($event->get('surveyId'));

        $menuItem = new \LimeSurvey\Menu\MenuItem([
            'label' => $this->getName(),
            'href' => $this->api->createUrl(
                'admin/pluginhelper',
                array_merge([
                    'sa'     => 'sidebody',
                    'plugin' => 'ResponseQualityChecker',
                    'method' => 'actionIndex',
                    'sid' => $this->survey->primaryKey,
                ])
            ),
            'iconClass' => 'fa fa-exclamation-triangle  text-info',

        ]);
        $menuItems[] = $menuItem;
        $event->set('menuItems', $menuItems);
        return $menuItems;

    }

    private function loadSurveySettings(){
        Yii::log("Trying to load survey settings from global", "info", __METHOD__);

        $event = $this->event;
        $globalSettings = $this->getPluginSettings(true);

        $surveySettings = [];
        foreach ($globalSettings as $key => $setting) {
            $currentSurveyValue = $this->get($key, 'Survey', $event->get('survey'));
            $surveySettings[$key] = $setting;
            if(!empty($currentSurveyValue)) {
                $surveySettings[$key]['current'] = $currentSurveyValue;
            }
        }
        Yii::log("Setting survey settings", "trace", __METHOD__);
        $event->set("surveysettings.{$this->id}", [
            'name' => get_class($this),
            'settings' => $surveySettings,
        ]);
    }

    private function checkWholeSurvey()
    {
        if(!$this->enabled()) {
            Yii::log('plugin disabled' , 'trace', __METHOD__);
            return;
        }
        $this->questions = $this->checkableQuestions();

        $model = SurveyDynamic::model($this->survey->primaryKey);
        $criteria = new CDbCriteria();
        $criteria->addCondition('submitdate is not null');
        $models = $model->findAll($criteria);
        if(count($models) == 0) {
            Yii::log('no responses found' , 'trace', __METHOD__);
            return;
        }
        foreach ($models as $model) {
            $this->checkResponse($model);
        }

    }

    private function checkableQuestions()
    {
        $criteria = $this->getQuestionOrderCriteria();

        $criteria->addColumnCondition([
            't.sid' => $this->survey->primaryKey,
            'parent_qid' => 0,
        ]);
        if(!$this->isV4plusVersion()) {
            $criteria->addColumnCondition([
                't.language' => $this->survey->language,
            ]);
        }
        $arrayQuestionTypes = [
            '1', // array dual scale
            'A', // array (5 point choice)
            'B', // array (10 point choice)
            'C', // array (yes/no/Uncertain)
            'E', // array (increase/same/decrease)
            'F', // array (Flexible Labels)
            'H', // array (Flexible Labels) by column
        ];
        $criteria->addInCondition('t.type', $arrayQuestionTypes);

        /** @var Question[] $questions */
        $questions = Question::model()->findAll($criteria);
        return $questions;
    }


    private function findQuestionByName(string $name) : ?Question
    {
        $criteria = new CDbCriteria();
        $criteria->addColumnCondition([
            'sid' => $this->survey->primaryKey,
            'parent_qid' => 0,
            'title' => $name,
        ]);
        if(!$this->isV4plusVersion()) {
            $criteria->addColumnCondition([
                'language' => $this->survey->language,
            ]);
        }
        return Question::model()->find($criteria);
    }






    private function beforeAction($sid) {
        $this->defaults();
        $this->survey = Survey::model()->findByPk($sid);
        $this->targetQuestion();
        $this->appNameQuestion();

    }

    private function getQuestionOrderCriteria()
    {
        $criteria = new CDbCriteria();
        $criteria->select = Yii::app()->db->quoteColumnName('t.*');
        $criteria->with = [
            'survey.groups',
        ];

        if (Yii::app()->db->driverName == 'sqlsrv' || Yii::app()->db->driverName == 'dblib'){
            $criteria->order = Yii::app()->db->quoteColumnName('t.question_order');
        } else {
            $criteria->order = Yii::app()->db->quoteColumnName('groups.group_order').','.Yii::app()->db->quoteColumnName('t.question_order');
        }
        $criteria->addCondition('groups.gid=t.gid', 'AND');
        return $criteria;

    }

    private function loadSurvey()
    {

        $event = $this->event;
        $surveyId = $event->get('surveyid');
        if(empty($surveyId)) {
            return;
        }
        //Yii::log("Loading survey $surveyId", "info", __METHOD__);

        /**
         * NB need to do it without find() since the code at hand is itself run
         * after find() resulting in infinite loop
         */
        $query = Yii::app()->db->createCommand()
            ->select('*')
            ->from(Survey::model()->tableName())
            ->where('sid=:sid')
            ->bindParam(':sid', $surveyId, PDO::PARAM_STR);
        $surveyArray = $query->queryRow();

        if (empty($surveyArray)) {
            Yii::log("Got empty survey", "trace", __METHOD__);
            return;
        }
        //Yii::log("Creating a survey from array", "info", __METHOD__);
        $this->survey = (new Survey());
        $this->survey->attributes = $surveyArray;

    }

    private function checkQuestionQuality(Question $question, SurveyDynamic $response) : QualityResult
    {
        return $this->checkStraightLining($question, $response);
    }

    private function unSubmitEnabled(): bool
    {
        return boolval($this->settingValue('unSubmitEnabled'));
    }

    private function threshold(): float
    {
        return floatval($this->settingValue('threshold'));
    }

    private function externalAppNameQuestionName(): ?string
    {
        return trim(strval($this->settingValue('externalAppNameQuestion')));
    }
    private function responseIdFieldName(): ?string
    {
        return trim(strval($this->settingValue('responseIdFieldName')));
    }

    private function  responseIdQuestionFieldName():?string
    {
        $questionName = $this->responseIdFieldName();
        if($questionName === 'token') {
            return 'token';
        }
        if(empty($questionName)) {
            return null;
        }
        $question = $this->findQuestionByName($questionName);
        if(empty($question)) {
            return null;
        }
        return $question->getBasicFieldName();
    }


    private function apiConfig(string $appName): ?ApiConfig
    {
        Yii::log("Getting api config for $appName", "trace", __METHOD__);
        $config = $this->get("external-apps", 'Survey', $this->survey->primaryKey);
        $configArray = json_decode($config, true);
        if(empty($configArray)) {
            Yii::log("Empty api config for $appName", "trace", __METHOD__);
            return null;
        }
        if(!array_key_exists($appName, $configArray)) {
            Yii::log("No api config found for $appName", "trace", __METHOD__);
            return null;
        }
        $appConfig = $configArray[$appName];

        if(!isset($appConfig['url'])) {
            Yii::log("Api config url not found for $appName", "trace", __METHOD__);
            return null;
        }
        if(!isset($appConfig['auth-token'])) {
            Yii::log("Api config auth-token not found for $appName", "trace", __METHOD__);
            return null;
        }

        Yii::log("Creating ApiConfig for $appName", "trace", __METHOD__);
        return new ApiConfig($appConfig['url'], $appConfig['auth-token']);

    }

    private function isTrashResult(float $result): bool
    {
        $threshold = $this->threshold();
        if($result < $threshold) {
            return true;
        }
        return false;
    }

    /**
     * @param string $key
     * @return mixed|string|null
     */
    private function settingValue(string $key)
    {
        Yii::log("Looking for setting key $key", "trace", __METHOD__);
        $default = $this->settings[$key]['default'];
        $value = $this->get($key, 'Survey', $this->survey->primaryKey, $default);
        if($value == "" or $value == null) {
            $globalValue = $this->get($key, null, null, $default);
            if($globalValue == "" or $globalValue == null) {
                Yii::log("using default value for setting $key :" . $value, "trace", __METHOD__);
                return $default;
            }
            Yii::log("using global value for setting $key :" . $value, "trace", __METHOD__);
            return $globalValue;
        }
        Yii::log("using survey-value for setting $key :" . $value, "trace", __METHOD__);
        return $value;

    }


}
