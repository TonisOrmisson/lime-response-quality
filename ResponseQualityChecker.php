<?php

use Yii;
use LSYii_Application;

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

    private SurveyDynamic $response;

    /** @var Survey $survey */
    private Survey $survey;
    private int $totalSubQuestions = 0;
    private array $questions = [];
    /** @var null|Question|bool */
    private $targetQuestion = null;

    protected $settings = [
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
        'targetQuestion' => [
            'type' => 'string',
            'label' => 'Tha name of the question to store the quality result',
            'default'=>'quality',
        ],
    ];

    const SESSION_KEY = "ResponseQualityChecker";


    /* Register plugin on events*/
    public function init() {
        $this->subscribe('afterFindSurvey');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('beforeToolsMenuRender');
        $this->subscribe('newSurveySettings');

    }

    public function afterSurveyComplete()
    {
        $responseId = $this->event->get('responseId');
        Yii::log('afterSurveyComplete on response:' . $responseId, 'info', __METHOD__);

        $this->response = SurveyDynamic::model($this->survey->primaryKey)->findByPk($responseId);
        if(!($this->response instanceof SurveyDynamic)) {
            Yii::log('response not found' , 'info', __METHOD__);
            return;
        }
        Yii::log('found response:' . json_encode($this->response->attributes), 'info', __METHOD__);

        $this->loadSurvey();
        if(!$this->enabled()) {
            Yii::log('plugin disabled' , 'info', __METHOD__);
            return;
        }
        $this->questions = $this->checkableQuestions();
        $this->checkResponse($this->response);

    }

    private function checkWholeSurvey()
    {
        if(!$this->enabled()) {
            Yii::log('plugin disabled' , 'info', __METHOD__);
            return;
        }
        $this->questions = $this->checkableQuestions();

        $models = SurveyDynamic::model($this->survey->primaryKey);
        if($models->count() == 0) {
            Yii::log('no responses found' , 'info', __METHOD__);
            return;
        }
        foreach ($models->findAll() as $model) {
            $this->checkResponse($model);

        }

    }

    public function checkResponse(SurveyDynamic $response) {
        $questions = $this->questions;
        if(count($questions) == 0) {
            Yii::log('no questions found' , 'info', __METHOD__);
            return;
        }
        $totalQuality = 1.0;
        $questionQualities = [];
        Yii::log("found ".count($questions)." questions for quality check " , 'info', __METHOD__);
        foreach ($questions as $question) {
            $questionQuality = $this->checkQuestionQuality($question, $response);
            $questionQualities[] = $questionQuality;

            Yii::log($question->title . " [".round($questionQuality->getQuality() *100,1)."%] ". $question->question , 'info', __METHOD__);
        }
        if($this->totalSubQuestions > 0) {
            $totalQuality = 0.0;
            foreach ($questionQualities as $questionQuality) {
                $itemWeight = $questionQuality->getItems() / $this->totalSubQuestions;
                $itemQuality = $questionQuality->getQuality() * $itemWeight;
                $totalQuality += $itemQuality;
            }
        }

        Yii::log("total Subquestions " . $this->totalSubQuestions , 'info', __METHOD__);
        Yii::log("totalQuality " .round($totalQuality * 100, 0). "%" , 'info', __METHOD__);
        $this->saveResult($totalQuality, $response);

    }


    private function checkQuestionQuality(Question $question, SurveyDynamic $response) : QualityResult
    {
        return $this->checkStraightLining($question, $response);
    }


    public function checkStraightLining(Question $question, SurveyDynamic $response) : QualityResult
    {
        $subQuestions = $question->subquestions;
        $result = new QualityResult();
        if(count($subQuestions) == 0) {
            Yii::log('no subQuestions found' , 'info', __METHOD__);
            return $result;
        }
        Yii::log("found ".count($subQuestions)." subQuestions for quality check " , 'info', __METHOD__);
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
            Yii::log('no answers found' , 'info', __METHOD__);
            return $result;
        }

        Yii::log("answers ". json_encode($answers)  , 'info', __METHOD__);

        $counts = array_count_values($answers);
        arsort($counts);
        Yii::log("counts ". json_encode($counts)  , 'info', __METHOD__);
        $mostCheckedAnswer = current( $counts);
        $overlapPct = $mostCheckedAnswer / count($answers);
        if($overlapPct > 0.5 or $mostCheckedAnswer > 5) {
            $result->setQuality(1- $overlapPct);
        } else {
            $result->setQuality(1.0);
        }
        Yii::log("counts ". json_encode($counts)  , 'info', __METHOD__);
        Yii::log("most checked ". $mostCheckedAnswer  , 'info', __METHOD__);
        Yii::log("overlapping % ". round($overlapPct * 100, 0). "%"  , 'info', __METHOD__);
        return $result;
    }


    private function saveResult(float $totalQuality, SurveyDynamic $response) : void
    {
        $totalQuality = round($totalQuality, 3);
        $targetQuestion = $this->targetQuestion();
        if(!($targetQuestion instanceof Question)) {
            Yii::log('target question NOT found, not able to save result' . json_encode($targetQuestion->attributes) , 'info', __METHOD__);
            return;
        }
        Yii::log('target question found' . json_encode($targetQuestion->attributes) , 'info', __METHOD__);

        $sgqa = $targetQuestion->getBasicFieldName();
        /** @var CDbConnection $db */
        $db = Yii::app()->db;
        $rows = $db->createCommand()
            ->update(
                $this->survey->getResponsesTableName(),
                [$sgqa => $totalQuality],
                'id='.$response->id
            );
        Yii::log("Saved $rows records result " . $totalQuality , 'info', __METHOD__);

    }

    public function targetQuestion() : ?Question
    {
        if($this->targetQuestion !== null) {
            return $this->targetQuestion;
        }
        $targetQuestionName = $this->get('targetQuestion','Survey', $this->survey->primaryKey);
        Yii::log('looking for target question ' . $targetQuestionName, 'info', __METHOD__);
        $targetQuestion = $this->findQuestionByName($targetQuestionName);
        if($targetQuestion === null) {
            $this->targetQuestion = false;
        }
        Yii::log('found target question ' . $targetQuestionName, 'info', __METHOD__);
        $this->targetQuestion = $targetQuestion;
        return $targetQuestion;

    }


    public function afterFindSurvey() {
        $this->loadSurvey();
        if (empty($this->survey)) {
            return;
        }
        $surveyId = $this->survey->primaryKey;

    }

    public function enabled(): bool
    {
        return boolval($this->get("enabled", 'Survey', $this->survey->primaryKey));
    }

    private function sessionKey()
    {
        return self::SESSION_KEY."::".$this->survey->primaryKey;
    }

    private function loadSurvey()
    {

        $event = $this->event;
        $surveyId = $event->get('surveyid');
        if(empty($surveyId)) {
            return;
        }
        Yii::log("Loading survey $surveyId", "info", __METHOD__);

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
            Yii::log("Got empty survey", "info", __METHOD__);
            return;
        }
        Yii::log("Creating a survey from array", "info", __METHOD__);
        $this->survey = (new Survey());
        $this->survey->attributes = $surveyArray;

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
        Yii::log("Setting survey settings", "info", __METHOD__);
        $event->set("surveysettings.{$this->id}", [
            'name' => get_class($this),
            'settings' => $surveySettings,
        ]);
    }


    public function newSurveySettings()
    {
        $event = $this->event;

        foreach ($event->get('settings') as $name => $value) {
            $this->set($name, $value, 'Survey', $event->get('survey'));
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



    public function actionIndex($sid)
    {
        $this->beforeAction($sid);
        if (Yii::app()->request->isPostRequest) {
            $this->checkWholeSurvey();
        }

        return $this->renderPartial('index',
            [
                'survey' => $this->survey,
                'targetQuestion' => $this->targetQuestion,
            ],
            true
        );
    }

    private function beforeAction($sid) {
        $this->survey = Survey::model()->findByPk($sid);
        $this->targetQuestion();

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


}
