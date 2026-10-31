import { createTypeSafeAi } from "@ai-sdk/typesafe-ai";
import { createOpenAICompatible } from "@ai-sdk/openai-compatible";
import { wrapLanguageModel } from "ai";
import { modelSettings, assertReplayEnvironment } from "./config.ts";

export function createModels(env: NodeJS.ProcessEnv, replay: boolean) {
  if (replay) assertReplayEnvironment(env);
  const settings = modelSettings(replay ? {} : env);
  const deny = () => {
    console.error("CI_MODEL_CALL_FORBIDDEN");
    throw new Error("CI_MODEL_CALL_FORBIDDEN");
  };
  const transport = Object.assign(
    replay
      ? async () => deny()
      : async (input: Parameters<typeof fetch>[0], init?: RequestInit) => fetch(input, init),
    { preconnect: deny },
  );
  const rawDecision = createTypeSafeAi({ ...settings.decision, fetch: transport }).decisionModel(
    settings.decision.model,
  );
  const decision = replay
    ? {
        specificationVersion: rawDecision.specificationVersion,
        provider: rawDecision.provider,
        modelId: rawDecision.modelId,
        supportedQuestionTypes: rawDecision.supportedQuestionTypes,
        doDecide: async () => deny(),
        doEvaluate: async () => deny(),
      }
    : rawDecision;
  const rawText = createOpenAICompatible({
    name: "e2e-text",
    ...settings.text,
    fetch: transport,
  }).chatModel(settings.text.model);
  const text = replay
    ? wrapLanguageModel({
        model: rawText,
        middleware: {
          specificationVersion: "v4",
          wrapGenerate: async () => deny(),
          wrapStream: async () => deny(),
        },
      })
    : rawText;
  return { decision, text };
}
