<?php
/**
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Vanilla\Utility\DebugUtils;

/**
 * Email layer abstraction
 *
 * This class implements fluid method chaining.
 *
 * @author Mark O'Sullivan <markm@vanillaforums.com>
 * @author Todd Burry <todd@vanillaforums.com>
 * @package Core
 * @since 2.0
 */
class Gdn_Email extends Gdn_Pluggable implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Error: The email was not attempted to be sent.. */
    const ERR_SKIPPED = 1;

    /** @var bool */
    private $debug;

    /** @var PHPMailer */
    public $PhpMailer;

    /** @var boolean */
    private $_IsToSet;

    /** @var array Recipients that were skipped because they lack permission. */
    public $Skipped = [];

    /** @var EmailTemplate The email body renderer. Use this to edit the email body. */
    protected $emailTemplate;

    /** @var string The format of the email. */
    protected $format;

    /** @var string[] The supported email formats. */
    public static $supportedFormats = ["html", "text"];

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->PhpMailer = new \Vanilla\VanillaMailer();
        $this->PhpMailer->CharSet = "utf-8";
        $this->PhpMailer->SingleTo = c("Garden.Email.SingleTo", false);
        $this->PhpMailer->Hostname = c("Garden.Email.Hostname", "");
        $this->PhpMailer->Encoding = "quoted-printable";
        $this->clear();
        $this->addHeader("Precedence", "list");
        $this->addHeader("X-Auto-Response-Suppress", "All");
        $this->setEmailTemplate(new EmailTemplate());

        // Default debug status to the site config.
        $this->setDebug((bool) c("Garden.Email.Debug"));

        // This class is largely instantiated at the usage site, not the container, so we can't rely on it to wire up the dependency.
        $this->setLogger(Logger::getLogger());

        $this->resolveFormat();
        parent::__construct();
    }

    /**
     * Sets the format property based on the config, and defaults to html.
     */
    protected function resolveFormat()
    {
        $configFormat = c("Garden.Email.Format", "text");
        if (in_array(strtolower($configFormat), self::$supportedFormats)) {
            $this->setFormat($configFormat);
        } else {
            $this->setFormat("text");
        }
    }

    /**
     * Sets the format property, the email mime type and the email template format property.
     *
     * @param string $format The format of the email. Must be in the $supportedFormats array.
     * @return Gdn_Email
     */
    public function setFormat($format)
    {
        if (strtolower($format) === "html") {
            $this->format = "html";
            $this->mimeType("text/html");
            $this->emailTemplate->setPlaintext(false);
        } else {
            $this->format = "text";
            $this->mimeType("text/plain");
            $this->emailTemplate->setPlaintext(true);
        }
        return $this;
    }

    /**
     * Get email format
     *
     * Returns 'text' or 'html'.
     *
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * Add a custom header to the outgoing email.
     *
     * @param string $name
     * @param string $value
     * @since 2.1
     * @return Gdn_Email
     */
    public function addHeader($name, $value)
    {
        $this->PhpMailer->addCustomHeader("$name:$value");
        return $this;
    }

    /**
     * Adds to the "Bcc" recipient collection.
     *
     * @param mixed $recipientEmail An email (or array of emails) to add to the "Bcc" recipient collection.
     * @param string $recipientName The recipient name associated with $recipientEmail. If $recipientEmail is
     * an array of email addresses, this value will be ignored.
     * @return Gdn_Email
     */
    public function bcc($recipientEmail, $recipientName = "")
    {
        if ($recipientName != "" && c("Garden.Email.OmitToName", false)) {
            $recipientName = "";
        }

        ob_start();
        $this->PhpMailer->addBCC($recipientEmail, $recipientName);
        ob_end_clean();
        return $this;
    }

    /**
     * Adds to the "Cc" recipient collection.
     *
     * @param mixed $recipientEmail An email (or array of emails) to add to the "Cc" recipient collection.
     * @param string $recipientName The recipient name associated with $recipientEmail. If $recipientEmail is
     * an array of email addresses, this value will be ignored.
     * @return Gdn_Email
     */
    public function cc($recipientEmail, $recipientName = "")
    {
        if ($recipientName != "" && c("Garden.Email.OmitToName", false)) {
            $recipientName = "";
        }

        ob_start();
        $this->PhpMailer->addCC($recipientEmail, $recipientName);
        ob_end_clean();
        return $this;
    }

    /**
     * Clears out all previously specified values for this object and restores
     * it to the state it was in when it was instantiated.
     *
     * @return Gdn_Email
     */
    public function clear()
    {
        $this->PhpMailer->clearAllRecipients();
        $this->PhpMailer->Body = "";
        $this->PhpMailer->AltBody = "";
        $this->from();
        $this->_IsToSet = false;
        $this->mimeType(c("Garden.Email.MimeType", "text/plain"));
        $this->_MasterView = "email.master";
        $this->Skipped = [];
        return $this;
    }

    /**
     * Get the site's default from address.
     *
     * @return string
     */
    public function getDefaultFromAddress(): string
    {
        $result = c("Garden.Email.SupportAddress", "");
        if (!$result) {
            $result = $this->getNoReplyAddress();
        }
        return $result;
    }

    /**
     * Get the site's default sender (smtp envelope) address.
     *
     * @return string
     */
    public function getDefaultSenderAddress(): string
    {
        $result = c("Garden.Email.EnvelopeAddress", "");
        if (!$result) {
            $result = $this->getDefaultFromAddress();
        }
        return $result;
    }

    /**
     * Get an address suitable for no-reply-style emails.
     *
     * @return string
     */
    public function getNoReplyAddress(): string
    {
        $host = Gdn::request()->host();
        $result = "noreply@{$host}";
        return $result;
    }

    /**
     * Allows the explicit definition of the email's sender address & name.
     * Defaults to the applications Configuration 'SupportEmail' & 'SupportName' settings respectively.
     *
     * @param string $fromEmail
     * @param string $fromName
     * @param boolean $bOverrideSender optional. default false.
     * @return Gdn_Email
     */
    public function from($fromEmail = "", $fromName = "", $bOverrideSender = false)
    {
        if ($fromEmail == "") {
            $fromEmail = $this->getDefaultFromAddress();
        }

        if ($fromName == "") {
            $fromName = c("Garden.Email.SupportName", c("Garden.Title", ""));
        }

        if ($this->PhpMailer->Sender == "" || $bOverrideSender) {
            $envelopeEmail = $bOverrideSender ? $fromEmail : $this->getDefaultSenderAddress();
            $this->PhpMailer->Sender = $envelopeEmail;
        }

        ob_start();
        $this->PhpMailer->setFrom($fromEmail, $fromName, false);
        ob_end_clean();
        return $this;
    }

    /**
     * Allows the definition of a masterview other than the default: "email.master".
     *
     * @deprecated since version 2.2
     * @param string $masterView
     * @return Gdn_Email
     */
    public function masterView($masterView)
    {
        deprecated(__METHOD__);
        return $this;
    }

    /**
     * The message to be sent.
     *
     * @param string $message The body of the message to be sent.
     * @param boolean $convertNewlines Optional. Convert newlines to br tags
     * @param boolean $filter Optional. Filter HTML.
     * @return Gdn_Email
     */
    public function message($message, $convertNewlines = true, $filter = true)
    {
        $this->emailTemplate->setMessage($message, $convertNewlines, $filter);
        return $this;
    }

    public function formatMessage($message)
    {
        if ($this->PhpMailer->ContentType == "text/html") {
            $textVersion = false;
            if (stristr($message, "<!-- //TEXT VERSION FOLLOWS//")) {
                $emailParts = explode("<!-- //TEXT VERSION FOLLOWS//", $message);
                $textVersion = array_pop($emailParts);
                $message = array_shift($emailParts);
                $textVersion = trim(
                    strip_tags(preg_replace('/<(head|title|style|script)[^>]*>.*?<\/\\1>/s', "", $textVersion))
                );
                $message = trim($message);
            }

            $this->PhpMailer->msgHTML(self::imageInlineStyles($message));
            if ($textVersion !== false && !empty($textVersion)) {
                $textVersion = html_entity_decode($textVersion);
                $this->PhpMailer->AltBody = $textVersion;
            }
        } else {
            $this->PhpMailer->Body = $message;
        }
        return $this;
    }

    /**
     * @return EmailTemplate The email body renderer.
     */
    public function getEmailTemplate()
    {
        return $this->emailTemplate;
    }

    /**
     * @param EmailTemplate $emailTemplate The email body renderer.
     * @return Gdn_Email
     */
    public function setEmailTemplate($emailTemplate)
    {
        $this->emailTemplate = $emailTemplate;

        // if we change email templates after construct, inform it of the current format
        if ($this->format) {
            $this->setFormat($this->format);
        }
        return $this;
    }

    /**
     *
     *
     * @param $template
     * @return bool|mixed|string
     */
    public static function getTextVersion($template)
    {
        if (stristr($template, "<!-- //TEXT VERSION FOLLOWS//")) {
            $emailParts = explode("<!-- //TEXT VERSION FOLLOWS//", $template);
            $textVersion = array_pop($emailParts);
            $textVersion = trim(
                strip_tags(preg_replace('/<(head|title|style|script)[^>]*>.*?<\/\\1>/s', "", $textVersion))
            );
            return $textVersion;
        }
        return false;
    }

    /**
     *
     *
     * @param $template
     * @return mixed|string
     */
    public static function getHTMLVersion($template)
    {
        if (stristr($template, "<!-- //TEXT VERSION FOLLOWS//")) {
            $emailParts = explode("<!-- //TEXT VERSION FOLLOWS//", $template);
            array_pop($emailParts);
            $message = array_shift($emailParts);
            $message = trim($message);
            return $message;
        }
        return $template;
    }

    /**
     * Replace image classes with inline styles for proper layout in email
     *
     * @param $message
     * @return mixed|string
     */
    public static function imageInlineStyles($message)
    {
        // The patterns to search for as an array
        $patterns = [];
        // The string to replace the corresponding pattern index
        $replacements = [];

        // replace the class embedImage-img with inline styles
        $patterns[] = '/class="embedImage-img"/';
        $replacements[] =
            'style="height: auto; display: inline-flex; position: relative; margin-left: auto; margin-right: auto; max-width: 100%; max-height: 100%;"';

        // replace the class embedImage-link with inline styles
        $patterns[] = '/class="embedImage-link"/';
        $replacements[] = 'style="display: inline-flex; flex-direction: column;"';

        $embedImageStyle = "width: 100%; display: block;";
        // the different size options and styles
        $displayStyles = [
            "small" => "max-width: calc(33.333% - 1px);",
            "medium" => "max-width: calc(66.666% - 1px);",
            "large" => "max-width: 100%",
        ];
        // the different float options and styles
        $floatStyles = [
            "center" => "float: none; margin-top: 16px;",
            "left" => "float: left; margin-right: 20px; margin-bottom: 16px;",
            "right" => "float: right; margin-left: 20px; margin-bottom: 16px;",
        ];

        // add the various combination possibilities to the patterns and replacements
        foreach ($displayStyles as $displayKey => $displayStyle) {
            $displayClass = "display-" . $displayKey;

            foreach ($floatStyles as $floatKey => $floatStyle) {
                $floatClass = "float-" . $floatKey;

                $patterns[] = '/class="embedExternal embedImage ' . $displayClass . " " . $floatClass . '"/';
                $replacements[] = 'style="width: 100%; display: block; ' . $displayStyle . " " . $floatStyle . '"';
            }
        }

        return preg_replace($patterns, $replacements, $message);
    }

    /**
     * Sets the mime-type of the email.
     *
     * Only accept text/plain or text/html.
     *
     * @param string $mimeType The mime-type of the email.
     * @return Gdn_Email
     */
    public function mimeType($mimeType)
    {
        $this->PhpMailer->isHTML($mimeType === "text/html");
        return $this;
    }

    /**
     * Send the email.
     *
     * @param string $eventName
     * @return boolean
     * @throws \Exception Throws an exception if emailing is disabled.
     * @throws \PHPMailer\PHPMailer\Exception Throws an exception if there is a problem sending the email.
     */
    public function send($eventName = "")
    {
        $this->formatMessage($this->emailTemplate->toString());
        $this->fireEvent("BeforeSendMail");

        if (c("Garden.Email.Disabled")) {
            throw new Exception("Email disabled", self::ERR_SKIPPED);
        }

        if (DebugUtils::isTestMode()) {
            // Don't actually send emails in tests.
            return true;
        }

        if (c("Garden.Email.UseSmtp")) {
            $this->PhpMailer->isSMTP();
            $smtpHost = c("Garden.Email.SmtpHost", "");
            $smtpPort = c("Garden.Email.SmtpPort", 25);
            if (strpos($smtpHost, ":") !== false) {
                [$smtpHost, $smtpPort] = explode(":", $smtpHost);
            }

            $this->PhpMailer->Host = $smtpHost;
            $this->PhpMailer->Port = $smtpPort;
            $this->PhpMailer->SMTPSecure = c("Garden.Email.SmtpSecurity", "");
            $this->PhpMailer->Username = $username = c("Garden.Email.SmtpUser", "");
            $this->PhpMailer->Password = $password = c("Garden.Email.SmtpPassword", "");
            if (!empty($username)) {
                $this->PhpMailer->SMTPAuth = true;
            }
        } else {
            $this->PhpMailer->isMail();
        }

        if ($eventName != "") {
            $this->EventArguments["EventName"] = $eventName;
            $this->fireEvent("SendMail");
        }

        if (!empty($this->Skipped) && count($this->PhpMailer->getAllRecipientAddresses()) == 0) {
            // We've skipped all recipients.
            throw new Exception("No valid email recipients.", self::ERR_SKIPPED);
        }

        $this->PhpMailer->setThrowExceptions(true);
        if (!$this->PhpMailer->send()) {
            throw new Exception($this->PhpMailer->ErrorInfo);
        }

        if ($this->isDebug() && $this->logger instanceof Psr\Log\LoggerInterface) {
            $this->logger->info("Email Payload", [
                Vanilla\Logger::FIELD_CHANNEL => Vanilla\Logger::CHANNEL_SYSTEM,
                "event" => "email_sent",
                "timestamp" => time(),
                "userid" => Gdn::session()->UserID,
                "username" => Gdn::session()->User->Name ?? "anonymous",
                "ip" => Gdn::request()->ipAddress(),
                "method" => Gdn::request()->requestMethod(),
                "domain" => rtrim(url("/", true), "/"),
                "path" => Gdn::request()->path(),
                "charset" => $this->PhpMailer->CharSet,
                "contentType" => $this->PhpMailer->ContentType,
                "from" => $this->PhpMailer->From,
                "fromName" => $this->PhpMailer->FromName,
                "sender" => $this->PhpMailer->Sender,
                "subject" => $this->PhpMailer->Subject,
                // Address data comes in an array of [address, name] items and we only need the address.
                "to" => array_column($this->PhpMailer->getToAddresses(), 0),
                "cc" => array_column($this->PhpMailer->getCcAddresses(), 0),
                "bcc" => array_column($this->PhpMailer->getBccAddresses(), 0),
            ]);
        }
        return true;
    }

    /**
     * Adds subject of the message to the email.
     *
     * @param string $subject The subject of the message.
     * @return Gdn_Email
     */
    public function subject($subject)
    {
        $this->PhpMailer->Subject = mb_encode_mimeheader($subject, $this->PhpMailer->CharSet);
        return $this;
    }

    public function addTo($recipientEmail, $recipientName = "")
    {
        if ($recipientName != "" && c("Garden.Email.OmitToName", false)) {
            $recipientName = "";
        }

        ob_start();
        $this->PhpMailer->addAddress($recipientEmail, $recipientName);
        ob_end_clean();
        return $this;
    }

    /**
     * Adds to the "To" recipient collection.
     *
     * @param mixed $recipientEmail An email, an array of emails, or a user object to add to the "To" recipient collection.
     *   Note: Passing a user object adds Garden.Email.View permission checks.
     * @param string $recipientName The recipient name associated with $recipientEmail. If $recipientEmail is
     *   an array of email addresses, this value will be ignored.
     * @return Gdn_Email
     */
    public function to($recipientEmail, $recipientName = "")
    {
        if ($recipientName != "" && c("Garden.Email.OmitToName", false)) {
            $recipientName = "";
        }

        if (is_string($recipientEmail)) {
            if (strpos($recipientEmail, ",") > 0) {
                $recipientEmail = explode(",", $recipientEmail);
                // trim no need, PhpMailer::addAnAddress() will do it
                return $this->to($recipientEmail, $recipientName);
            }
            if ($this->PhpMailer->SingleTo) {
                return $this->addTo($recipientEmail, $recipientName);
            }
            if (!$this->_IsToSet) {
                $this->_IsToSet = true;
                $this->addTo($recipientEmail, $recipientName);
            } else {
                $this->cc($recipientEmail, $recipientName);
            }
            return $this;
        } elseif (
            (is_object($recipientEmail) && property_exists($recipientEmail, "Email")) ||
            (is_array($recipientEmail) && isset($recipientEmail["Email"]))
        ) {
            $user = $recipientEmail;
            $recipientName = val("Name", $user);
            $recipientEmail = val("Email", $user);
            $userID = val("UserID", $user, false);

            if ($userID !== false) {
                // Check to make sure the user can receive email.
                if (!Gdn::userModel()->checkPermission($userID, "Garden.Email.View")) {
                    $this->Skipped[] = $user;

                    return $this;
                }
            }

            return $this->to($recipientEmail, $recipientName);
        } elseif ($recipientEmail instanceof Gdn_DataSet) {
            foreach ($recipientEmail->resultObject() as $object) {
                $this->to($object);
            }
            return $this;
        } elseif (is_array($recipientEmail)) {
            $count = count($recipientEmail);
            if (!is_array($recipientName)) {
                $recipientName = array_fill(0, $count, "");
            }
            if ($count == count($recipientName)) {
                $recipientEmail = array_combine($recipientEmail, $recipientName);
                foreach ($recipientEmail as $email => $name) {
                    $this->to($email, $name);
                }
            } else {
                trigger_error(errorMessage("Size of arrays do not match", "Email", "To"), E_USER_ERROR);
            }

            return $this;
        }

        trigger_error(
            errorMessage(
                "Incorrect first parameter (" . gettype($recipientEmail) . ") passed to function.",
                "Email",
                "To"
            ),
            E_USER_ERROR
        );
    }

    public function charset($use = "")
    {
        if ($use != "") {
            $this->PhpMailer->CharSet = $use;
            return $this;
        }
        return $this->PhpMailer->CharSet;
    }

    /**
     * Should mailing be debugged?
     *
     * @param boolean $debug
     */
    public function setDebug(bool $debug)
    {
        $this->debug = $debug;
    }

    /**
     * Is mailing being debugged?
     *
     * @return boolean
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }
}
