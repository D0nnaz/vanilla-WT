<?php
/**
 * SignedIn module.
 *
 * @copyright 2009-2019 Vanilla Forums Inc.
 * @license GPL-2.0-only
 * @package Dashboard
 * @since 2.0
 */

/**
 * Info about a user ban.
 */
class UserBanModule extends GDN_Module
{
    /** @var int The ban(s) to exclude from the reasons. */
    public $ExcludeBans = 0;

    /** @var string The translation code for the summary. */
    public $Summary;

    /** @var int UserID The user ID we are looking at. Default to the current user. */
    public $UserID;

    /**
     * Initialize a new instance of the {@link UserBanModule} class.
     */
    public function __construct()
    {
        parent::__construct();
        $this->_ApplicationFolder = "dashboard";
    }

    /**
     *
     *
     * @throws Exception
     */
    protected function getData()
    {
        $userID = $this->UserID ?: Gdn::session()->UserID;
        $user = Gdn::userModel()->getID($userID);

        $banned = val("Banned", $user);
        $bits = BanModel::explodeBans($banned);
        $reasons = [];

        foreach ($bits as $bit) {
            if (($bit & $this->ExcludeBans) === 0) {
                $reasons[$bit] = t("BanReason.$bit");
            }

            // Add a link to identify the corresponding ban rule.
            if ($bit === 2) {
                $text = t("Find the matching ban rule(s).");
                $reasons[$bit] .= " " . anchor($text, "settings/bans/find/" . $userID);
            }
        }

        $this->setData("Reasons", $reasons);

        if (!$this->Summary) {
            if ($this->ExcludeBans) {
                $summary = "Also banned for the following:";
            } else {
                $summary = "Banned for the following:";
            }
        }
        $this->setData("Summary", $this->Summary ?: $summary);

        $this->EventArguments["User"] = $user;
        $this->fireEvent("GetData");
    }

    /**
     *
     *
     * @return string
     */
    public function toString()
    {
        if (!Gdn::session()->checkPermission("Garden.Moderation.Manage")) {
            // Only moderators can view the reasons for being banned.
            return "";
        }

        $this->getData();

        if (empty($this->Data["Reasons"])) {
            return "";
        } else {
            return parent::toString();
        }
    }
}
