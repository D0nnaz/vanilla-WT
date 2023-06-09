/*!
 * @author Isis (igraziatto) Graziatto <isis.g@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

import React from "react";
import { onReady } from "@library/utility/appUtils";
import { AjaxModal } from "../AjaxModal";
import { loaderClasses } from "@library/loaders/loaderStyles";
import Loader from "@library/loaders/Loader";
import ModalSizes from "@library/modal/ModalSizes";
import { mountModal } from "@library/modal/mountModal";
import { t } from "@vanilla/i18n";
import gdn from "@library/gdn";
import { addComponent } from "@library/utility/componentRegistry";
import { DiscussionListModule } from "@library/features/discussions/DiscussionListModule";
import { registerQnaSearchTypes } from "../registerQnaSearchTypes";

/**
 * Hijack click because api request needs to be a POST
 *
 * @param e
 */
export async function hijackFollowUpClick(e) {
    const target = e.target;

    if (target.tagName === "A" && target.classList.contains("QnAFollowUp")) {
        e.preventDefault();
        e.stopPropagation();
        const discussionID = gdn.meta.DiscussionID ?? null;

        let url = target
            .getAttribute("href")
            .match(/(api\/v2).*/)[0]
            .replace("api/v2", "");
        const data = { discussionID: discussionID };
        const modal = (
            <AjaxModal
                size={ModalSizes.MEDIUM}
                url={url}
                data={data}
                loader={<Loader padding={100} loaderStyleClass={loaderClasses().mediumLoader} />}
                title={t("Send Q&A Follow-up Email")}
            />
        );

        mountModal(modal);
    }
}

onReady(() => {
    registerQnaSearchTypes();
    document.addEventListener("click", hijackFollowUpClick);
});

addComponent("DiscussionQuestionsWidget", DiscussionListModule, { overwrite: true });
