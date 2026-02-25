Legend: [ ] Incomplete, [X] Complete

# SPRINT-001 Traceability Matrix

This matrix maps NLSpec definition-of-done sections to executable test coverage and verification commands.

## Unified LLM (`unified-llm-spec.md` Section 8)

| DoD Section | Coverage | Primary Tests |
|---|---|---|
| 8.1 Core Infrastructure | [X] | `tests/unit/LLM/ClientTest.php` |
| 8.2 Provider Adapters | [X] | `tests/integration/LLM/AdapterTranslationTest.php`, `tests/integration/LLM/ErrorRetryTest.php` |
| 8.3 Message & Content Model | [X] | `tests/integration/LLM/AdapterTranslationTest.php` |
| 8.4 Generation | [X] | `tests/unit/LLM/HighLevelTest.php` |
| 8.5 Reasoning Tokens | [X] | `tests/integration/LLM/AdapterTranslationTest.php` |
| 8.6 Prompt Caching | [X] | `tests/integration/LLM/AdapterTranslationTest.php` |
| 8.7 Tool Calling | [X] | `tests/unit/LLM/HighLevelTest.php` |
| 8.8 Error Handling & Retry | [X] | `tests/integration/LLM/ErrorRetryTest.php` |
| 8.9 Cross-Provider Parity | [X] (deterministic) | `tests/integration/LLM/AdapterTranslationTest.php` |
| 8.10 Integration Smoke Test | [X] (env-gated) | `tests/e2e/ProviderSmokeE2eTest.php` |

## Coding Agent Loop (`coding-agent-loop-spec.md` Section 9)

| DoD Section | Coverage | Primary Tests |
|---|---|---|
| 9.1 Core Loop | [X] | `tests/unit/Agent/SessionTest.php` |
| 9.2 Provider Profiles | [X] | `tests/unit/Agent/ProfileTest.php` |
| 9.3 Tool Execution | [X] | `tests/unit/Agent/SessionTest.php` |
| 9.4 Execution Environment | [X] | `tests/unit/Agent/LocalExecutionEnvironmentTest.php` |
| 9.5 Tool Output Truncation | [X] | `tests/unit/Agent/SessionTest.php` |
| 9.6 Steering | [X] | `tests/unit/Agent/SessionTest.php` |
| 9.7 Reasoning Effort | [X] | `tests/unit/Agent/ProfileTest.php` |
| 9.8 System Prompts | [X] | `tests/unit/Agent/ProfileTest.php` |
| 9.9 Subagents | [X] | `tests/unit/Agent/ProfileTest.php` |
| 9.10 Event System | [X] | `tests/unit/Agent/SessionTest.php` |
| 9.11 Error Handling | [X] | `tests/unit/Agent/SessionTest.php`, `tests/unit/Agent/LocalExecutionEnvironmentTest.php` |
| 9.12 Cross-Provider Parity Matrix | [X] (deterministic) | `tests/unit/Agent/SessionTest.php`, `tests/unit/Agent/ProfileTest.php` |
| 9.13 Integration Smoke Test | [X] (env-gated) | `tests/e2e/ProviderSmokeE2eTest.php` |

## Attractor Runner (`attractor-spec.md` Section 11)

| DoD Section | Coverage | Primary Tests |
|---|---|---|
| 11.1 DOT Parsing | [X] | `tests/unit/Pipeline/DotParserTest.php` |
| 11.2 Validation and Linting | [X] | `tests/unit/Pipeline/ValidatorTest.php` |
| 11.3 Execution Engine | [X] | `tests/unit/Pipeline/RunnerTest.php` |
| 11.4 Goal Gate Enforcement | [X] | `tests/unit/Pipeline/RunnerTest.php` |
| 11.5 Retry Logic | [X] | `tests/unit/Pipeline/RunnerTest.php` |
| 11.6 Node Handlers | [X] | `tests/unit/Pipeline/RunnerTest.php`, `tests/integration/Pipeline/PipelineSmokeTest.php` |
| 11.7 State and Context | [X] | `tests/unit/Pipeline/RunnerTest.php` |
| 11.8 Human-in-the-Loop | [X] | `tests/unit/Pipeline/RunnerTest.php` |
| 11.9 Condition Expressions | [X] | `tests/unit/Pipeline/ConditionEvaluatorTest.php` |
| 11.10 Model Stylesheet | [X] | `tests/unit/Pipeline/DotParserTest.php`, `tests/unit/Pipeline/RunnerTest.php` |
| 11.11 Transforms and Extensibility | [X] | `tests/unit/Pipeline/RunnerTest.php` |
| 11.12 Cross-Feature Parity Matrix | [X] (deterministic) | `tests/unit/Pipeline/RunnerTest.php`, `tests/integration/Pipeline/PipelineSmokeTest.php` |
| 11.13 Integration Smoke Test | [X] (deterministic + env-gated path) | `tests/integration/Pipeline/PipelineSmokeTest.php`, `tests/e2e/ProviderSmokeE2eTest.php` |

## Verification Commands

- Deterministic full suite: `timeout 180 make test`
- Deterministic lint/build gate: `timeout 180 make build`
- Provider smoke suite (skips when API keys are absent): `timeout 180 ./bin/composer run test:e2e:provider-smoke`
