const express = require("express");

const { getPool } = require("../db");

const router = express.Router();
const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

router.get("/", async (request, response) => {
  const adminKey = process.env.ADMIN_API_KEY;

  if (!adminKey || request.get("x-admin-key") !== adminKey) {
    return response.status(401).json({ error: "Unauthorized" });
  }

  const limit = Math.min(
    Math.max(Number.parseInt(request.query.limit, 10) || 20, 1),
    100,
  );
  const offset = Math.max(Number.parseInt(request.query.offset, 10) || 0, 0);

  try {
    const [users] = await getPool().query(
      `SELECT id, name, email, interested_in AS interestedIn,
              budget_in_usd AS budgetInUsd, project_details AS projectDetails,
              created_at AS createdAt, updated_at AS updatedAt
       FROM users
       ORDER BY created_at DESC
       LIMIT ? OFFSET ?`,
      [limit, offset],
    );
    const [[count]] = await getPool().query(
      "SELECT COUNT(*) AS total FROM users",
    );

    return response.json({
      users,
      pagination: { total: Number(count.total), limit, offset },
    });
  } catch (error) {
    console.error("User listing failed:", error.message);
    return response.status(500).json({ error: "Unable to load users" });
  }
});

router.post("/", async (request, response) => {
  const { name, email, interestedIn, budgetInUsd, budget, projectDetails } =
    request.body || {};

  const normalizedName = typeof name === "string" ? name.trim() : "";
  const normalizedEmail =
    typeof email === "string" ? email.trim().toLowerCase() : "";
  const normalizedInterestedIn =
    typeof interestedIn === "string" ? interestedIn.trim() : null;
  const normalizedBudgetInUsd =
    typeof budgetInUsd === "string"
      ? budgetInUsd.trim()
      : typeof budget === "string"
        ? budget.trim()
        : null;
  const normalizedProjectDetails =
    typeof projectDetails === "string" ? projectDetails.trim() : null;

  if (!normalizedName || normalizedName.length > 120) {
    return response
      .status(400)
      .json({ error: "Name is required and must be 120 characters or fewer" });
  }

  if (!emailPattern.test(normalizedEmail) || normalizedEmail.length > 255) {
    return response.status(400).json({ error: "A valid email is required" });
  }

  try {
    const [result] = await getPool().execute(
      `INSERT INTO users (name, email, interested_in, budget_in_usd, project_details)
       VALUES (?, ?, ?, ?, ?)`,
      [
        normalizedName,
        normalizedEmail,
        normalizedInterestedIn || null,
        normalizedBudgetInUsd || null,
        normalizedProjectDetails || null,
      ],
    );

    return response.status(201).json({
      message: "User details saved successfully",
      user: {
        id: result.insertId,
        name: normalizedName,
        email: normalizedEmail,
        interestedIn: normalizedInterestedIn,
        budgetInUsd: normalizedBudgetInUsd,
        projectDetails: normalizedProjectDetails,
      },
    });
  } catch (error) {
    if (error.code === "ER_DUP_ENTRY") {
      return response
        .status(409)
        .json({ error: "A user with this email already exists" });
    }

    console.error("User creation failed:", error.message);
    return response.status(500).json({ error: "Unable to save user details" });
  }
});

module.exports = router;
