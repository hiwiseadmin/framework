<?php
namespace plugin\shortlink\event;

use plugin\shortlink\model\Link;

class LinkExpiredEvent
{
    public $link;
    public function __construct(Link $link) { $this->link = $link; }
}
