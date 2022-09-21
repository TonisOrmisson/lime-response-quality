<?php

trait AppTrait
{
    public function isV4plusVersion() : bool
    {
        return intval(\Yii::app()->getConfig("versionnumber")) > 3;
    }
}
