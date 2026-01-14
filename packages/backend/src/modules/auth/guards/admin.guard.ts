import { Injectable, CanActivate, ExecutionContext, ForbiddenException } from '@nestjs/common';
import { UserRole } from '@prisma/client';

@Injectable()
export class AdminGuard implements CanActivate {
  canActivate(context: ExecutionContext): boolean {
    const request = context.switchToHttp().getRequest();
    const user = request.user;

    if (!user) {
      throw new ForbiddenException('Authentication required');
    }

    // Allow ADMIN, GOVERNOR, and MODERATOR roles to access admin panel
    const allowedRoles = [UserRole.ADMIN, UserRole.GOVERNOR, UserRole.MODERATOR];

    if (!allowedRoles.includes(user.role)) {
      throw new ForbiddenException('Admin access required');
    }

    return true;
  }
}
