import "dotenv/config";
import cron from "node-cron";
import config from "../config/index";
import { initDatabase } from "../infrastructure/persistence/database";
import { runReindex } from "./reindex-core";

async function main(): Promise<void> {
  if (!config.reindexEnabled) {
    console.log("Reindex scheduler disabled (REINDEX_ENABLED=false).");
    return;
  }

  const expression = config.reindexCron;
  if (!cron.validate(expression)) {
    console.error(`Invalid REINDEX_CRON expression: "${expression}"`);
    process.exit(1);
  }

  await initDatabase();

  let running = false;

  cron.schedule(
    expression,
    async () => {
      if (running) {
        console.warn("Skipping reindex: previous run still in progress.");
        return;
      }
      running = true;
      try {
        console.log(`[${new Date().toISOString()}] Scheduled reindex starting...`);
        const summary = await runReindex({});
        console.log(
          `[${new Date().toISOString()}] Reindex done in ${summary.seconds}s — scanned=${summary.scanned} ` +
            `indexed=${summary.indexed} skipped=${summary.skipped} failed=${summary.failed}` +
            `${summary.resized ? " (column resized)" : ""}`
        );
      } catch (error) {
        console.error("Scheduled reindex failed:", (error as Error).message);
      } finally {
        running = false;
      }
    },
    { timezone: config.reindexTz }
  );

  console.log(`Reindex scheduler started — cron="${expression}" tz=${config.reindexTz}`);
}

main().catch((error) => {
  console.error("Scheduler failed to start:", error);
  process.exit(1);
});
