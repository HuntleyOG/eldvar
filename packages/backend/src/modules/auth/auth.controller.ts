import { Controller, Post, Body, UseGuards, Request, HttpCode } from '@nestjs/common';
import { AuthService } from './auth.service';
import { LocalAuthGuard } from './guards/local-auth.guard';

@Controller('auth')
export class AuthController {
  constructor(private authService: AuthService) {}

  @UseGuards(LocalAuthGuard)
  @Post('login')
  @HttpCode(200)
  async login(@Request() req: any) {
    return this.authService.login(req.user);
  }

  @Post('register')
  async register(
    @Body('username') username: string,
    @Body('password') password: string,
    @Body('email') email?: string,
  ) {
    return this.authService.register(username, password, email);
  }

  @Post('logout')
  @HttpCode(200)
  async logout(@Request() req: any) {
    req.logout(() => {});
    return { message: 'Logged out successfully' };
  }
}
