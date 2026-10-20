<?php
namespace plugin\shortlink\event;

use plugin\shortlink\model\Link;
use plugin\shortlink\model\Log;

class LinkVisitedEvent
{
    public $link;
    public $log;
    public function __construct(Link $link, Log $log) { $this->link = $link; $this->log = $log; }
}
