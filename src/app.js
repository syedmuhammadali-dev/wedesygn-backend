require('dotenv').config();

const express = require('express');

const app = express();

app.disable('x-powered-by');
app.use(express.json());

app.get('/api/health', (_request, response) => {
  response.status(200).json({
    status: 'ok',
    service: 'wedesygn-backend',
    environment: process.env.NODE_ENV || 'development',
    timestamp: new Date().toISOString(),
  });
});

module.exports = app;
