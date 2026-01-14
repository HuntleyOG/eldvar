import { NestFactory } from '@nestjs/core';
import { ValidationPipe } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import helmet from 'helmet';
import session from 'express-session';
import { AppModule } from './app.module';

async function bootstrap() {
  const app = await NestFactory.create(AppModule);
  const configService = app.get(ConfigService);

  // Security
  app.use(helmet());

  // Session
  app.use(
    session({
      secret: configService.get<string>('SESSION_SECRET')!,
      resave: false,
      saveUninitialized: false,
      cookie: {
        maxAge: configService.get<number>('SESSION_MAX_AGE') || 86400000, // Default 24 hours
        httpOnly: true,
        secure: configService.get<string>('NODE_ENV') === 'production',
        sameSite: 'lax',
      },
    }),
  );

  // CORS
  app.enableCors({
    origin: configService.get<string>('CORS_ORIGIN'),
    credentials: true,
  });

  // Global prefix
  const apiPrefix = configService.get<string>('API_PREFIX');
  if (apiPrefix) {
    app.setGlobalPrefix(apiPrefix);
  }

  // Validation
  app.useGlobalPipes(
    new ValidationPipe({
      whitelist: true,
      forbidNonWhitelisted: true,
      transform: true,
      transformOptions: {
        enableImplicitConversion: true,
      },
    }),
  );

  const port = configService.get<number>('PORT') || 3000;
  await app.listen(port);

  console.log(`🚀 Eldvar API is running on: http://localhost:${port}${apiPrefix ? `/${apiPrefix}` : ''}`);
}

bootstrap();
