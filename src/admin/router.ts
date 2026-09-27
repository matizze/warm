import { Router, Request, Response, NextFunction } from "express";
import config from "../config/index";
import { QueryService } from "../domain/services/query-service";
import { createReindexContext, runReindex, ReindexSummary } from "../scripts/reindex-core";

interface LastRun {
  startedAt: string;
  finishedAt?: string;
  summary?: ReindexSummary;
  error?: string;
}

export function createAdminRouter(queryService: QueryService): Router {
  const router = Router();
  const context = createReindexContext(queryService);

  let running = false;
  let last: LastRun | null = null;

  function authenticate(req: Request, res: Response, next: NextFunction): void {
    if (!config.adminToken) {
      res.status(404).json({ status: "not_found" });
      return;
    }
    const header = req.header("x-admin-token") || (req.header("authorization") || "").replace(/^Bearer\s+/i, "");
    if (header !== config.adminToken) {
      res.status(401).json({ status: "unauthorized" });
      return;
    }
    next();
  }

  router.get("/admin/reindex", authenticate, (_req, res) => {
    res.json({ running, last });
  });

  router.post("/admin/reindex", authenticate, (req, res) => {
    if (running) {
      res.status(409).json({ status: "already_running", last });
      return;
    }

    const force = req.query.force === "true" || req.body?.force === true;
    running = true;
    last = { startedAt: new Date().toISOString() };

    runReindex({ force }, context)
      .then((summary) => {
        last = { ...(last as LastRun), finishedAt: new Date().toISOString(), summary };
      })
      .catch((error) => {
        last = { ...(last as LastRun), finishedAt: new Date().toISOString(), error: (error as Error).message };
      })
      .finally(() => {
        running = false;
      });

    res.status(202).json({ status: "started", force });
  });

  return router;
}
