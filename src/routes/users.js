const express = require("express");

const { getPool } = require("../db");

const router = express.Router();
const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

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
