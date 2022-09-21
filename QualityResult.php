<?php

class QualityResult
{
    private float $quality = 1.0;
    private int $items = 0;

    /**
     * @return float
     */
    public function getQuality(): float
    {
        return $this->quality;
    }

    /**
     * @param float $quality
     */
    public function setQuality(float $quality): void
    {
        $this->quality = $quality;
    }

    /**
     * @return int
     */
    public function getItems(): int
    {
        return $this->items;
    }

    /**
     * @param int $items
     */
    public function setItems(int $items): void
    {
        $this->items = $items;
    }

    public function addItems(int $items) : void
    {
        $this->items += $items;
    }


}
