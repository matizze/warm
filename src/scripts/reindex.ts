import "dotenv/config";
import config from "../config/index";
import { initDatabase } from "../infrastructure/persistence/database";
import { runReindex } from "./reindex-core";

interface CliOptions {
  batch: number;
  concurrency: number;
  force: boolean;
  dryRun: boolean;
}

function parseArgs(): CliOptions {
  const args = process.argv.slice(2);
  const value = (name: string, fallback: string): string => {
    const hit = args.find((a) => a.startsWith(`--${name}=`));
    return hit ? hit.split("=")[1] : fallback;
  };
  return {
    batch: parseInt(value("batch", "100"), 10),
    concurrency: parseInt(value("concurrency", "4"), 10),
    force: args.includes("--force"),
    dryRun: args.includes("--dry-run"),
  };
}

async function main(): Promise<void> {
  const opts = parseArgs();
  await initDatabase();

  console.log(
    `Reindex started — model=${config.embeddingModel} dims=${config.embeddingDimensions} ` +
      `batch=${opts.batch} concurrency=${opts.concurrency} ` +
      `${opts.force ? "FORCE(all)" : "incremental"}${opts.dryRun ? " (dry-run)" : ""}`
  );

  const summary = await runReindex({
    batch: opts.batch,
    concurrency: opts.concurrency,
    force: opts.force,
    dryRun: opts.dryRun,
  });

  console.log(
    `\nReindex finished in ${summary.seconds}s — scanned=${summary.scanned} indexed=${summary.indexed} ` +
      `skipped=${summary.skipped} failed=${summary.failed}${summary.resized ? " (column resized)" : ""}`
  );
  process.exit(0);
}

main().catch((error) => {
  console.error("Reindex failed:", error);
  process.exit(1);
});
