import config from "../../config/index";
import { MediaProcessor, ProcessedContent, ProcessingContext } from "../../domain/services/media-processor";

const PROMPT =
  "Analise a imagem. Responda SOMENTE em JSON com as chaves: " +
  '"description" (descrição objetiva da imagem em português) e ' +
  '"text" (todo o texto visível na imagem, ou string vazia se não houver).';

export class ImageProcessor implements MediaProcessor {
  name = "image";

  supports(context: ProcessingContext): boolean {
    return context.type === "image" || context.mimeType.startsWith("image/");
  }

  async process(buffer: Buffer, context: ProcessingContext): Promise<ProcessedContent | null> {
    if (!config.openaiApiKey) {
      return null;
    }

    const dataUrl = `data:${context.mimeType || "image/jpeg"};base64,${buffer.toString("base64")}`;

    const response = await fetch(`${config.openaiBaseUrl}/chat/completions`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${config.openaiApiKey}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        model: config.visionModel,
        response_format: { type: "json_object" },
        messages: [
          {
            role: "user",
            content: [
              { type: "text", text: PROMPT },
              { type: "image_url", image_url: { url: dataUrl } },
            ],
          },
        ],
      }),
    });

    if (!response.ok) {
      throw new Error(`Vision processing failed (${response.status}): ${await response.text()}`);
    }

    const json = (await response.json()) as { choices?: { message?: { content?: string } }[] };
    const raw = json.choices?.[0]?.message?.content || "{}";

    let description = "";
    let ocr = "";
    try {
      const parsed = JSON.parse(raw) as { description?: string; text?: string };
      description = (parsed.description || "").trim();
      ocr = (parsed.text || "").trim();
    } catch {
      description = raw.trim();
    }

    const text = [description, ocr ? `Texto na imagem: ${ocr}` : ""].filter(Boolean).join("\n");

    return {
      kind: "description",
      text,
      metadata: { model: config.visionModel, processor: this.name, ocr: ocr || undefined },
    };
  }
}
