<?php

use tonisormisson\version\Version;

/** @var Survey $survey */
/** @var AdminController $this */
/** @var ?Question $targetQuestion */

$this->pageTitle = "import";
?>


<div id='relevance-imex'>
    <?php if($targetQuestion instanceof Question):?>
        <div class="page-header"><span class="h1">Check all responses</span> </div>
            <div class="tab-content">
                <?= CHtml::form(null, 'post'); ?>
                <input type='submit' class = "btn btn-success btn-xl " value='<?php eT("Check all survey responses"); ?>' />
                <input type='hidden' name='sid' value='<?= $survey->primaryKey;?>' />
                <?php echo CHtml::endForm() ?>
            </div>
        </div>
    <?php else:?>
        no target question
    <?php endif;?>

</div>

</div>
