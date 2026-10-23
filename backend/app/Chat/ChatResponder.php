<?php

namespace App\Chat;

/**
 * Whatever answers the chatbot's questions (18.13). V1 is App\Chat\KnowledgeBaseResponder (the admin's knowledge base,
 * live numbers and the site's content, free). A paid model added later implements this same interface, so the API
 * and the site do not change.
 */
interface ChatResponder
{
    /** A question typed by a visitor (already checked and cleared of personal data). */
    public function answer(string $question): ChatAnswer;

    /** A button the visitor pressed (18.6). Null when the button does not exist or is switched off. */
    public function press(int $quickReplyId): ?ChatAnswer;
}
