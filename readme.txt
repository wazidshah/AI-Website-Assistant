=== AI Website Assistant ===
Contributors: wazidshah
Tags: ai, chatbot, gemini, assistant, support
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight AI chatbot powered by Google Gemini that answers visitor questions using your actual website content (RAG).

== Description ==

**AI Website Assistant** adds an intelligent chat widget to your WordPress site. It automatically learns from your pages, posts, and custom post types, then uses Google Gemini to answer visitor questions accurately — based only on your content, never making things up.

= How it works =

1. You connect your free Google Gemini API key in Settings → AI Assistant.
2. Click **Train** to crawl and index your website content.
3. A floating chat bubble appears on your site for visitors to ask questions.

The assistant uses **RAG (Retrieval-Augmented Generation)**: it searches your indexed content for the most relevant chunks, then passes them to Gemini as context. This means answers are grounded in your actual website — not hallucinated.

= Key Features =

* **Semantic Search** — Uses Gemini Embeddings (text-embedding-004) for vector-based retrieval, with a keyword fallback.
* **Conversation History** — Remembers previous messages within a session for natural back-and-forth exchanges.
* **Noise-Free Crawling** — Intelligently strips navbars, footers, sidebars, and cookie banners so only real content is indexed.
* **Customisable System Prompt** — Define the AI's personality and rules from the admin dashboard with `{site_name}` token support.
* **Suggested Questions** — Add clickable quick-reply buttons to the chat widget to guide visitors.
* **Appearance Controls** — Set a primary colour, custom font, and bot profile picture without touching code.
* **Admin-Only Mode** — Hide the chatbot from visitors while you test it.
* **Rate Limiting** — Built-in per-IP rate limiting to protect your API quota.
* **WooCommerce Ready** — Optionally index product pages for e-commerce Q&A.
* **ACF Support** — Optionally index Advanced Custom Fields for richer content.

= Requirements =

* A free [Google AI Studio API key](https://aistudio.google.com/app/apikey)
* WordPress 6.0+
* PHP 7.4+

== Installation ==

1. Upload the `ai-website-assistant` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings → AI Assistant** and enter your Google Gemini API key.
4. Go to the **Training** tab and click **Start Training** to index your content.
5. Visit your website — the chat bubble will appear in the bottom-right corner.

== Frequently Asked Questions ==

= Is Google Gemini free to use? =

Yes. The Gemini API has a generous free tier (Gemini 2.0 Flash). You can get your API key at [Google AI Studio](https://aistudio.google.com/app/apikey) — no credit card required.

= Will it answer questions about topics NOT on my website? =

No. The assistant is instructed to only answer questions based on your indexed content, and to politely decline out-of-scope questions.

= How often should I retrain? =

Retrain whenever you make significant changes to your content — new pages, updated services, pricing changes, etc.

= Can I restrict the chatbot to admins only? =

Yes. Enable **Admin-Only Mode** in Settings → AI Assistant to hide the widget from regular visitors while you're testing.

= Does this work with WooCommerce? =

Yes. Enable **WooCommerce Products** under Crawler & Indexing to include product pages.

= Does it store visitor messages? =

No. Visitor questions are sent directly to the Gemini API and are not stored in your database. Only your website content chunks and embeddings are stored locally.

== Screenshots ==

1. The floating chat widget on the frontend.
2. The Settings page — API key, model, and bot configuration.
3. The Training page — crawl and index your website content.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Semantic search with Gemini Embeddings + cosine similarity.
* Keyword search fallback (FULLTEXT + LIKE).
* Conversation history support.
* Semantic content extraction (targets main/article/entry-content, strips nav/footer/sidebar).
* Correct asymmetric embedding task types (RETRIEVAL_QUERY for questions, RETRIEVAL_DOCUMENT for chunks).
* Admin-only mode, rate limiting, ACF support, WooCommerce product indexing.
* Appearance customisation: colour, font, bot picture.
* Suggested questions (quick reply buttons).
* Dynamic Gemini model selector (fetched live from API).

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade required.
