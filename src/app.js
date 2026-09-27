require("dotenv").config();

const express = require("express");
const cors = require("cors");
const helmet = require("helmet");
const usersRouter = require("./routes/users");

const app = express();
const allowedOrigins = (process.env.CORS_ORIGIN || "*")
  .split(",")
  .map((origin) => origin.trim())
  .filter(Boolean);

const corsOptions = {
  origin: (requestOrigin, callback) => {
    if (
      !requestOrigin ||
      allowedOrigins.includes("*") ||
      allowedOrigins.includes(requestOrigin)
    ) {
      return callback(null, true);
    }

    return callback(new Error("Origin is not allowed by CORS"));
  },
};

app.disable("x-powered-by");
app.use(helmet());
app.use(cors(corsOptions));
app.use(express.json());
app.use("/api/users", usersRouter);
app.use("/api/create-user", usersRouter);

app.get("/api/health", (_request, response) => {
  response.status(200).json({
    status: "ok",
    service: "wedesygn-backend",
    environment: process.env.NODE_ENV || "development",
    timestamp: new Date().toISOString(),
  });
});

module.exports = app;
