import { Router } from "express";
import { initDatabase } from "../persistence/database";

async function migrate(): Promise<void> {
  await initDatabase();
  console.log("Database migration complete");
}

migrate().catch((err) => {
  console.error("Migration failed:", err);
  process.exit(1);
});
