import config from "../../config/index";

export interface TranscriptionInput {
  mimeType: string;
  filename: string;
}

export async function transcribe(buffer: Buffer, input: TranscriptionInput): Promise<string> {
  if (!config.openaiApiKey) {
    throw new Error("OPENAI_API_KEY is not set; cannot transcribe");
  }

  const form = new FormData();
  form.append("file", new Blob([new Uint8Array(buffer)], { type: input.mimeType }), input.filename);
  form.append("model", config.transcriptionModel);

  const response = await fetch(`${config.openaiBaseUrl}/audio/transcriptions`, {
    method: "POST",
    headers: { Authorization: `Bearer ${config.openaiApiKey}` },
    body: form,
  });

  if (!response.ok) {
    throw new Error(`Transcription failed (${response.status}): ${await response.text()}`);
  }

  const json = (await response.json()) as { text?: string };
  return (json.text || "").trim();
}
