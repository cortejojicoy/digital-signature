<?php

namespace Kukux\DigitalSignature\Hub\Cms;

/**
 * Building, timestamping or reading a CMS signature failed. The message says
 * which input was wrong; nothing here is retried silently.
 */
class CmsException extends \RuntimeException {}
