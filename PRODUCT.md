# Arbor — company knowledge, made navigable

PHP-first local application for a company knowledge tree, initially fewer than 500 English Markdown/SOP documents. JavaScript and HTML are welcome; no React or frontend build system. Content is opaque: instructions such as "Yes → step2" are not executable workflow rules. API callers own their progress.

Core tasks: discover collections, browse menus, read exact content, search, import and organize sources, edit and publish snapshots. Every reference has a stable node ID and version. Raw sources are preserved. AI-generated semantic relations are distinct from explicit source references.

Two surfaces: a knowledge workspace and a separate Traversal Lab using the actual HTTP tools. The lab accepts commands, runs them individually, displays raw responses and actual trace events, and highlights visited nodes on a real tree.

Model support: LLM required for AI operations; Jev optional. Deterministic browsing, reading, editing and lexical search work without credentials. Never represent lexical or fixture output as model output. PHP adapters support OpenAI-compatible APIs, Anthropic and AWS Bedrock Converse. Credentials remain server-side.

First release is a local proof of concept, not a multi-tenant SaaS. No external service is called until configured and requested.
