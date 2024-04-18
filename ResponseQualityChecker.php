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
    private int $straightLiningItemsCount = 0;
    private int $dontKnowItemsCount = 0;
    private array $straightLiningQuestions = [];
    /** @var null|Question|bool */
    private $targetQuestion = null;
    /** @var null|Question|bool */
    private $appNameQuestion = null;
    protected $settings = [];
    private bool $sendApiRequest = true;
    private float $totalQuality = 1.0;
    private float $straightLineQuality = 1.0;
    private float $dontKnowQuality = 1.0;
    private float $timingQuality = 1.0;
    public float $minSecondsAllowedOnItem = 1.0;

    /** @var float $pageBaseMinSeconds absolute minimum time needed to be on one page */
    public float $pageBaseMinSeconds = 1.0;

    public function __construct(\LimeSurvey\PluginManager\PluginManager $manager, $id)
    {
        parent::__construct($manager, $id);
        $this->defaults();

    }


    /* Register plugin on events*/
    public function init() {
        Yii::log("#################################### Initializing plugin", "trace", $this->logCategory());

        $this->subscribe('afterFindSurvey');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('beforeToolsMenuRender');
        $this->subscribe('newSurveySettings');

    }


    public function afterSurveyComplete()
    {
        $responseId = $this->event->get('responseId');
        Yii::log('afterSurveyComplete on response:' . $responseId, 'trace', $this->logCategory());

        $response = SurveyDynamic::model($this->survey->primaryKey)->findByPk($responseId);
        if(!($response instanceof SurveyDynamic)) {
            Yii::log('response not found' , 'info', $this->logCategory());
            return;
        }
        Yii::log('found response:' . $response->id, 'trace', $this->logCategory());

        $this->loadSurvey();
        if(!$this->enabled()) {
            Yii::log('plugin disabled' , 'trace', $this->logCategory());
            return;
        }
        $this->checkResponse($response);

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
        $this->sendApiRequest = false;
        /** @var LSYii_Application $app */
        $app = Yii::app();
        $request = $app->request;

        if ($request->isPostRequest) {
            $this->sendApiRequest = boolval($request->getPost('sendApiRequest'));
            Yii::log('sendApiRequest: ' . strval($this->sendApiRequest), 'trace', $this->logCategory());
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
     * The plugin should return setting metadata.
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


    private function checkResponse(SurveyDynamic $response) {
        $this->reset();
        $this->straightLiningQuestions = $this->straightLiningQuestions();

        $this->checkStraightLiningOnResponse($response);
        $this->checkDontKnowsOnResponse($response);
        $this->checkTimingsOnResponse($response);

        Yii::log("total SubQuestions " . $this->totalSubQuestions , 'info', $this->logCategory());
        Yii::log("totalQuality " .round($this->totalQuality * 100, 0). "%" , 'info', $this->logCategory());
        $this->saveResult($this->totalQuality, $response);
        if($this->isTrashResult($this->totalQuality) && $this->unSubmitEnabled()) {
            $this->unSubmitResponse($response);
        }

        if($this->sendApiRequest) {
            $this->sendResultToApp($response);
        }

    }

    private function reset()
    {
        $this->totalSubQuestions = 0;
        $this->straightLiningItemsCount = 0;
        $this->dontKnowItemsCount = 0;

        $this->totalQuality = 1.0;
        $this->straightLineQuality = 1.0;
        $this->dontKnowQuality = 1.0;
        $this->timingQuality = 1.0;

    }

    private function checkTimingsOnResponse(SurveyDynamic $response)
    {

        $groups = QuestionGroup::model()->findAllByAttributes(['sid'  => $this->survey->primaryKey]);
        if(!$this->survey->getHasTimingsTable()) {
            Yii::log("noTimingsTable, skipping that", 'info', $this->logCategory());
            return;
        }
        $timings = SurveyTimingDynamic::model($this->survey->primaryKey)
            ->findByPk($response->primaryKey);
        $data = [];
        if(empty($timings)) {
            Yii::log("noTimes", 'info', $this->logCategory());
            return;
        }
        Yii::log("Times:".json_encode($timings->attributes), 'trace', $this->logCategory());
        Yii::log("GroupsCount:".count($groups), 'trace', $this->logCategory());
        $okGroupsCount = 0;
        $relevantGroupsCount = 0;
        foreach ($groups as $group) {
            $sg = $this->survey->primaryKey."X".$group->primaryKey;
            $timeColName = $sg."time";
            if(!isset($timings[$timeColName]) or
                (isset($timings[$timeColName]) and $timings[$timeColName] == null)
            ) {
                Yii::log("groupNoTimings:".$group->gid, 'info', $this->logCategory());
                continue;
            }

            $relevantGroupsCount++;
            $groupTime = floatval($timings[$timeColName]);
            Yii::log("$timeColName:". $groupTime, 'trace', $this->logCategory());

            $relevantItemsCount = 0;
            $questionsOnPage = $group->getAllQuestions();
            $relevantItemsCount += count($questionsOnPage);
            $minAllowedSeconds = $this->pageBaseMinSeconds + ($relevantItemsCount * $this->minSecondsAllowedOnItem);
            if($groupTime >= $minAllowedSeconds) {
                $okGroupsCount++;
            } else {
                Yii::log("timingQualityNotOkFor:". $group->gid
                    . " allowed: ". round($minAllowedSeconds, 3)
                    . " actual: ". round($groupTime, 3), 'info', $this->logCategory());
            }

            $data[] = [
                'gid' => $group->primaryKey,
                'items' => $relevantItemsCount
            ];
        }
        $this->timingQuality = $okGroupsCount / $relevantGroupsCount;
        Yii::log("timingQuality:". json_encode($data), 'info', $this->logCategory());
        Yii::log("timingQuality:". $this->timingQuality, 'info', $this->logCategory());
        $this->totalQuality = $this->totalQuality * $this->timingQuality;

    }

    private function checkDontKnowsOnResponse(SurveyDynamic $response)
    {
        $dontKnowAnswers = $this->dontKnowAnswers();
        if(count($dontKnowAnswers) == 0) {
            Yii::log('noDontKnowAnswersFound' , 'trace', $this->logCategory());
            return;
        }
        $totalNoAnswerQuestionsIds = [];
        $countNoAnswers = 0;
        foreach ($dontKnowAnswers as $answer) {
            $question = $answer->question;
            $totalNoAnswerQuestionsIds[] = $question->qid;
            $totalNoAnswerQuestionsIds = array_unique($totalNoAnswerQuestionsIds);
            $field = $question->sid . 'X' . $question->gid . 'X' . $question->qid;
            if(!isset($response[$field])) {
                continue;
            }
            $value = $response[$field];
            if($value == $answer->code) {
                $countNoAnswers++;
                Yii::log("$field: ". $response[$field] , 'trace', $this->logCategory());
            }

        }

        $qualityResult = (new QualityResult());
        $this->dontKnowItemsCount = count($totalNoAnswerQuestionsIds);

        $noAnswerRate = $countNoAnswers / $this->dontKnowItemsCount;
        $noAnswerQuality= 1-$noAnswerRate;
        $qualityResult->setQuality($noAnswerQuality);
        $qualityResult->setItems(count($totalNoAnswerQuestionsIds));
        $this->straightLineQuality = $qualityResult->getQuality();
        $this->totalQuality = $this->totalQuality *$this->straightLineQuality;
        $logData = [
            'dontKnowItemsCount' => $this->dontKnowItemsCount,
            'countNoAnswers' => $countNoAnswers,
            'noAnswerRate' => $noAnswerRate,
            'straightLineQuality' => $this->straightLineQuality ,
            'totalQuality' => $this->totalQuality ,
        ];

        Yii::log(json_encode($response->attributes) , 'trace', $this->logCategory());
        Yii::log("noAnswerQuality:". json_encode($logData), 'info', $this->logCategory());
    }

    private function checkStraightLiningOnResponse(SurveyDynamic $response)
    {
        $questions = $this->straightLiningQuestions;
        if(count($questions) == 0) {
            Yii::log('noStraightLiningQuestionsFound' , 'info', $this->logCategory());
            return;
        }

        $questionQualities = [];
        Yii::log("found ".count($questions)." questions for straightLining quality check " , 'info', $this->logCategory());
        foreach ($questions as $question) {
            $questionQuality = $this->checkStraightLiningOnQuestion($question, $response);
            if($questionQuality->getItems() > 0) {
                $questionQualities[] = $questionQuality;
                Yii::log($question->title . " [".round($questionQuality->getQuality() *100,1)."%] ". $question->title , 'trace', $this->logCategory());
            }
        }
        if($this->straightLiningItemsCount > 0) {
            $this->straightLineQuality = 0.0;
            foreach ($questionQualities as $questionQuality) {
                $itemWeight = $questionQuality->getItems() / $this->straightLiningItemsCount;
                $itemQuality = $questionQuality->getQuality() * $itemWeight;
                $this->straightLineQuality += $itemQuality;
                Yii::log("straightLiningQuality:". $this->straightLineQuality, 'info', $this->logCategory());
            }
        }
        $this->straightLiningItemsCount  = count($questions);
        $this->totalQuality = $this->totalQuality *$this->straightLineQuality;
        Yii::log("straightLiningQuality:". $this->straightLineQuality, 'info', $this->logCategory());

    }



    private function checkStraightLiningOnQuestion(Question $question, SurveyDynamic $response) : QualityResult
    {
        Yii::log('checkStraightLining:sid:'. $this->survey->primaryKey . ":response:" . $response->id .":" , 'trace', $this->logCategory());
        $subQuestions = $question->subquestions;
        $result = new QualityResult();
        if(count($subQuestions) == 0) {
            Yii::log('no subQuestions found' , 'trace', $this->logCategory());
            return $result;
        }
        Yii::log("found ".count($subQuestions)." subQuestions for quality check " , 'trace', $this->logCategory());
        $answers = [];
        $sgq = $question->getBasicFieldName();
        foreach ($subQuestions as $subQuestion) {
            $sgqa = $sgq.$subQuestion->title;
            $answer = $response->$sgqa;
            if($answer == '' or $answer == null) {
                continue;
            }
            $this->straightLiningItemsCount++;
            $result->addItems(1);
            $answers[] = $answer;
            //Yii::log("checking $sgqa:". $answer  , 'info', $this->logCategory());
        }
        if(count($answers) == 0) {
            Yii::log('no answers found' , 'trace', $this->logCategory());
            return $result;
        }

        Yii::log("answers ". json_encode($answers)  , 'trace', $this->logCategory());

        $counts = array_count_values($answers);
        arsort($counts);
        $mostCheckedAnswer = current( $counts);
        $overlapPct = $mostCheckedAnswer / count($answers);
        if($overlapPct < 0.5 or $mostCheckedAnswer < 4) {
            $result->setQuality(1.0);
        } else {
            $result->setQuality(1- $overlapPct);
        }
        Yii::log("counts ". json_encode($counts)  , 'trace', $this->logCategory());
        Yii::log("most checked ". $mostCheckedAnswer  , 'trace', $this->logCategory());
        Yii::log("overlapping % ". round($overlapPct * 100, 0). "%"  , 'trace', $this->logCategory());
        return $result;
    }


    private function appNameQuestion() : ?Question
    {
        if($this->appNameQuestion !== null) {
            return $this->appNameQuestion;
        }
        $appNameQuestionName = $this->settingValue('externalAppNameQuestion');
        Yii::log('looking for app-name question ' . $appNameQuestionName, 'trace', $this->logCategory());
        $appNameQuestion = $this->findQuestionByName($appNameQuestionName);
        if($appNameQuestion === null) {
            $this->appNameQuestion = null;
            Yii::log("app-name question $appNameQuestionName not found"  , 'trace', $this->logCategory());
            return null;
        }

        Yii::log('found app-name question ' . $appNameQuestionName, 'trace', $this->logCategory());
        $this->appNameQuestion = $appNameQuestion;
        return $appNameQuestion;

    }


    private function targetQuestion() : ?Question
    {
        if($this->targetQuestion !== null) {
            return $this->targetQuestion;
        }
        $targetQuestionName = $this->settingValue('targetQuestion');
        Yii::log('looking for target question ' . $targetQuestionName, 'trace', $this->logCategory());
        $targetQuestion = $this->findQuestionByName($targetQuestionName);

        if($targetQuestion === null) {
            $this->targetQuestion = null;
            Yii::log("target question $targetQuestionName not found"  , 'trace', $this->logCategory());
            return null;
        }

        Yii::log('found target question ' . $targetQuestionName, 'trace', $this->logCategory());
        $this->targetQuestion = $targetQuestion;
        return $targetQuestion;

    }
    private function sendResultToApp(SurveyDynamic $response) : bool
    {
        Yii::log("sendResultToApp:" . $this->survey->primaryKey, 'info', $this->logCategory());
        $externalAppNameQuestionName = $this->externalAppNameQuestionName();
        if($externalAppNameQuestionName === null) {
            return false;
        }
        $appNameQuestion = $this->findQuestionByName($externalAppNameQuestionName);
        if($appNameQuestion === null) {
            Yii::log("could not find question $externalAppNameQuestionName in survey:" . $this->survey->primaryKey, 'error', $this->logCategory());
            return false;
        }
        $fieldName = $appNameQuestion->getBasicFieldName();

        $appName = $response->$fieldName;
        if(empty($appName)){
            Yii::log("no app name value found for resonse {$response->id} in survey:" . $this->survey->primaryKey, 'info', $this->logCategory());
            return false;
        }

        $apiConfig = $this->apiConfig($appName);

        if($apiConfig === null) {
            Yii::log("no api config found for app $appName in survey:" . $this->survey->primaryKey, 'info', $this->logCategory());
            return false;
        }

        Yii::log("Sending response quality to app $appName in survey:" . $this->survey->primaryKey, 'info', $this->logCategory());
        $postService = new ExternalPostService(
            $response,
            $apiConfig,
            $this
        );
        $postService->run();
        return true;
    }




    private function defaults() : void
    {
        if(count($this->settings)> 0 ) {
            return;
        }

        Yii::log("Setting defaults", "trace", $this->logCategory());

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
        Yii::log("Settings:" . json_encode($this->settings), "trace", $this->logCategory());

    }

    private function saveResult(float $totalQuality, SurveyDynamic $response) : void
    {
        $totalQuality = round($totalQuality, 3);
        $targetQuestion = $this->targetQuestion();
        if(!($targetQuestion instanceof Question)) {
            Yii::log('target question NOT found, not able to save result' . json_encode($targetQuestion->attributes) , 'trace', $this->logCategory());
            return;
        }
        Yii::log('target question found' . json_encode($targetQuestion->attributes) , 'trace', $this->logCategory());

        $sgqa = $targetQuestion->getBasicFieldName();
        /** @var CDbConnection $db */
        $db = Yii::app()->db;
        $rows = $db->createCommand()
            ->update(
                $this->survey->getResponsesTableName(),
                [$sgqa => $totalQuality],
                'id='.$response->id
            );
        Yii::log("Saved $rows records result " . $totalQuality , 'trace', $this->logCategory());

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
            Yii::log("UnSubmitted response " . $response->id , 'info', $this->logCategory());
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
        Yii::log("Trying to load survey settings from global", "info", $this->logCategory());

        $event = $this->event;
        $globalSettings = $this->getPluginSettings(true);


        $surveySettings = [];
        foreach ($globalSettings as $key => $setting) {
            $currentSurveyValue = $this->get($key, 'Survey', $this->survey->primaryKey);
            $surveySettings[$key] = $setting;
            if(!empty($currentSurveyValue)) {
                $surveySettings[$key]['current'] = $currentSurveyValue;
            }
        }
        Yii::log("Setting survey settings", "trace", $this->logCategory());
        $event->set("surveysettings.{$this->id}", [
            'name' => get_class($this),
            'settings' => $surveySettings,
        ]);
    }

    private function checkWholeSurvey()
    {
        if(!$this->enabled()) {
            Yii::log('plugin disabled' , 'trace', $this->logCategory());
            return;
        }
        $this->straightLiningQuestions = $this->straightLiningQuestions();

        $model = SurveyDynamic::model($this->survey->primaryKey);
        $criteria = new CDbCriteria();
        $criteria->addCondition('submitdate is not null');
        $models = $model->findAll($criteria);
        if(count($models) == 0) {
            Yii::log('no responses found' , 'trace', $this->logCategory());
            return;
        }
        foreach ($models as $model) {
            $this->checkResponse($model);
        }
    }

    /**
     * @return Answer[]
     */
    private function dontKnowAnswers() : array
    {
        $locator = 'class="eoo"';

        $criteria = new CDbCriteria();
        $criteria->select = Yii::app()->db->quoteColumnName('t.*');
        $criteria->with = [
            'question'
        ];

        $criteria->addCondition('question.sid='.$this->survey->primaryKey);

        if($this->isV4plusVersion()) {
            $criteria->with[] = 'answerl10ns';
            $criteria->addColumnCondition([
                'answerl10ns.language' => $this->survey->language,
            ]);
            $criteria->addSearchCondition('answerl10ns.answer', $locator );
        }else {
            $criteria->addColumnCondition([
                'question.language' => $this->survey->language,
            ]);
            $criteria->addSearchCondition('t.answer', $locator);
        }

        $answers = (new Answer())->findAll($criteria);
        return $answers;
    }

    /**
     * @return Question[]
     */
    private function straightLiningQuestions() : array
    {
        $arrayQuestionTypes = [
            '1', // array dual scale
            'A', // array (5 point choice)
            'B', // array (10 point choice)
            'C', // array (yes/no/Uncertain)
            'E', // array (increase/same/decrease)
            'F', // array (Flexible Labels)
            'H', // array (Flexible Labels) by column
        ];
        return $this->questionsByTypes($arrayQuestionTypes);
    }



    /**
     * @return Question[]
     */
    private function questionsByTypes(array $questionTypes) : array
    {
        Yii::log("questionsByTypes", "info", $this->logCategory());
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
        $criteria->addInCondition('t.type', $questionTypes);
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
        //Yii::log("Loading survey $surveyId", "info", $this->logCategory());

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
            Yii::log("Got empty surveys", "info", $this->logCategory());
            return;
        }
        //Yii::log("Creating a survey from array", "info", $this->logCategory());
        $this->survey = (new Survey());
        $this->survey->attributes = $surveyArray;

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

    public function  responseIdQuestionFieldName():?string
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
        Yii::log("Getting api config for $appName", "trace", $this->logCategory());
        $config = $this->settingValue('external-apps');
        $configArray = json_decode($config, true);
        if(empty($configArray)) {
            Yii::log("Empty api config for $appName", "trace", $this->logCategory());
            return null;
        }
        if(!array_key_exists($appName, $configArray)) {
            Yii::log("No api config found for $appName", "trace", $this->logCategory());
            return null;
        }
        $appConfig = $configArray[$appName];

        if(!isset($appConfig['url'])) {
            Yii::log("Api config url not found for $appName", "trace", $this->logCategory());
            return null;
        }
        if(!isset($appConfig['auth-token'])) {
            Yii::log("Api config auth-token not found for $appName", "trace", $this->logCategory());
            return null;
        }

        Yii::log("Creating ApiConfig for $appName", "trace", $this->logCategory());
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
        Yii::log("Looking for setting key $key", "trace", $this->logCategory());
        $default = $this->settings[$key]['default'];
        $value = $this->get($key, 'Survey', $this->survey->primaryKey);
        if($value == "" or $value == null) {
            $globalValue = $this->get($key);
            if($globalValue == "" or $globalValue == null) {
                Yii::log("using default value for setting $key :" . $value, "trace", $this->logCategory());
                return $default;
            }
            Yii::log("using global value for setting $key :" . $value, "trace", $this->logCategory());
            return $globalValue;
        }
        Yii::log("using survey-value for setting $key :" . $value, "trace", $this->logCategory());
        return $value;

    }

    /**
     * @return LSYii_Application
     */
    public function getApp(): LSYii_Application
    {
        return $this->app;
    }

    /**
     * @return string
     */
    public function getStorage(): string
    {
        return $this->storage;
    }

    /**
     * @return string
     */
    public static function getDescription(): string
    {
        return self::$description;
    }

    /**
     * @return string
     */
    public static function getName(): string
    {
        return self::$name;
    }

    /**
     * @return int
     */
    public function getTotalSubQuestions(): int
    {
        return $this->totalSubQuestions;
    }

    /**
     * @return array
     */
    public function getStraightLiningQuestions(): array
    {
        return $this->straightLiningQuestions;
    }

    /**
     * @return bool|Question|null
     */
    public function getTargetQuestion()
    {
        return $this->targetQuestion;
    }

    /**
     * @return bool|Question|null
     */
    public function getAppNameQuestion()
    {
        return $this->appNameQuestion;
    }

    /**
     * @return array
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * @return bool
     */
    public function isSendApiRequest(): bool
    {
        return $this->sendApiRequest;
    }

    /**
     * @return float
     */
    public function getTotalQuality(): float
    {
        return $this->totalQuality;
    }

    /**
     * @return float
     */
    public function getStraightLineQuality(): float
    {
        return $this->straightLineQuality;
    }

    /**
     * @return float
     */
    public function getDontKnowQuality(): float
    {
        return $this->dontKnowQuality;
    }

    /**
     * @return int
     */
    public function getStraightLiningItemsCount(): int
    {
        return $this->straightLiningItemsCount;
    }

    /**
     * @param int $straightLiningItemsCount
     */
    public function setStraightLiningItemsCount(int $straightLiningItemsCount): void
    {
        $this->straightLiningItemsCount = $straightLiningItemsCount;
    }

    /**
     * @return int
     */
    public function getDontKnowItemsCount(): int
    {
        return $this->dontKnowItemsCount;
    }

    /**
     * @param int $dontKnowItemsCount
     */
    public function setDontKnowItemsCount(int $dontKnowItemsCount): void
    {
        $this->dontKnowItemsCount = $dontKnowItemsCount;
    }

    /**
     * @return float
     */
    public function getTimingQuality(): float
    {
        return $this->timingQuality;
    }

    /**
     * @param float $timingQuality
     */
    public function setTimingQuality(float $timingQuality): void
    {
        $this->timingQuality = $timingQuality;
    }




}
