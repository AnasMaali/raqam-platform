# The chatbot (phase 8, package 3)

Decision 18. Free in V1: the admin's knowledge base, live numbers and the site's own content; no paid AI.

## How a question is answered

1. The question is checked: at most 500 characters; a link or code is refused (and nothing of it is kept).
   E-mail addresses and phone numbers are taken out before anything is matched or stored.
2. **Knowledge base** (`App\Chat\KnowledgeBase`): each entry is a question in several wordings, keywords and an
   answer (with links). Text is read in the search form (hamza, ta marbuta, diacritics, «ال», sound plurals: the same
   rules as the search) and question words («كيف»، «هل»، «شو»…) are dropped. The best wording by shared words
   (Dice ≥ 0.5) wins; a keyword found in the question is enough on its own.
3. An entry can add an **action**: a service, a page, a link (https), WhatsApp, or a **live answer** with today's
   currencies (the one named, or USD/JOD/EUR) or the weather (the city named, or the default city), read from the
   same cached boards as the site.
4. **No answer**: up to 3 of the site's closest published posts (the site's search, Meilisearch or its PostgreSQL
   fallback) and a WhatsApp link whose ready message carries the visitor's question.
5. Follow-up buttons can be offered after an answer.

The FAQ page's published questions (`/api/v1/faqs`, Chat → FAQ) are entries of their own automatically; their
wordings and keywords can be added in the knowledge base screen. A service's own FAQ stays on its page only.

`App\Chat\ChatResponder` is the interface; V1 binds `KnowledgeBaseResponder`. A paid model later implements the same
interface (the `chatbot_ai` switch exists for it) and the API does not change.

## API

- `GET /api/v1/chat`: on or off (switch `chatbot`), the welcome (setting `chatbot.welcome`), the first buttons.
  The site loads the chat only when the visitor opens it.
- `POST /api/v1/chat/messages`: from the site's server only (token + visitor address), JSON. `message` or `button`,
  plus the `conversation` token from the previous answer and the page `path`. The answer's `html` is safe as it is.
- Limits: 10 messages a minute and 200 a day per visitor (IPv6 by /64), 60 per conversation, 30 requests a minute.

## Privacy (18.9)

A conversation is anonymous: the browser keeps a random token, we keep only its HMAC; no name, no IP (not even an
HMAC of it in the database). Questions are stored without e-mails and phone numbers.

A conversation is kept **180 days** from when it began, then `retention:prune` (every night at 02:40) deletes it with
its messages (`raqam.retention.chat_conversations_days`). The chatbot figures of the panel cover that period.

## Panel (group «الشات بوت»)

- **Knowledge base**: question, answer (bold, links, lists; cleaned by an allow-list on save and again when shown),
  other wordings, keywords, action, follow-up buttons, on/off.
- **Buttons**: the first buttons (shown when the chat opens) and the follow-ups; order by dragging.
- **FAQ**: the FAQ page's questions, by dragging.
- **Unanswered questions** (18.10): with how many times each was asked; «حوّله لجواب» makes an entry in one step and
  takes it and the questions worded alike off the list; «تجاهل». Above the list: conversations, questions, the share
  answered (buttons are not questions), and the 20 most asked in 30 days (18.11).

`php artisan bot:install` adds the starting entries and the five first buttons: services and prices, advertise with
us, today's currencies, the weather, contact us (never overwrites).
