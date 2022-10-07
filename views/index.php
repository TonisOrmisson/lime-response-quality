<?php

use tonisormisson\version\Version;

/** @var Survey $survey */
/** @var AdminController $this */
/** @var ?Question $targetQuestion */
/** @var ?Question $appNameQuestion */
/** @var string $responseIdFieldName */
/** @var string $targetQuestionName */
/** @var string $externalAppNameQuestionName */

$this->pageTitle = "import";
?>


<div id='relevance-imex'>
    <?php if($targetQuestion instanceof Question):?>
        <div class="page-header"><span class="h1">Check all responses</span> </div>
            <div class="tab-content">
                <?= CHtml::form(null, 'post'); ?>
                <div class="alert alert-info">
                    The following will check all survey responses and calculate the quality score for each response.
                    The result score will be saved in the question <code><?=$targetQuestion->title?></code>.
                </div>
                <div>
                    <label for="sendApiRequest">Send results via API?</label>
                    <?= CHtml::checkBox('sendApiRequest')?>
                </div>
                <div class="alert alert-info">
                    The API request will use the field <code><?= $responseIdFieldName ?></code> as the response ID
                    while sending the response data to the external app. The external app is defined by the field
                    <code><?= $externalAppNameQuestionName ?></code> in the survey.
                </div>
                <?= CHtml::submitButton('Check all survey responses', [
                    'confirm'=> 'Are you Sure',
                    'class' =>  "btn btn-success btn-xl",
                ]); ?>
                <input type='hidden' name='sid' value='<?= $survey->primaryKey;?>' />
                <?php echo CHtml::endForm() ?>
            </div>
        </div>
    <?php else:?>
        <div class="alert alert-danger">
            The quality target question <code><?=$targetQuestionName?></code> does not exist in the survey.
        </div>
    <?php endif;?>

</div>

</div>
